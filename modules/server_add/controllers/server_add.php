<?php defined("SYSPATH") or die("No direct script access.");
/**
 * Gallery - a web based photo album viewer and editor
 * Copyright (C) 2000-2008 Bharat Mediratta
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or (at
 * your option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street - Fifth Floor, Boston, MA  02110-1301, USA.
 */
class Server_Add_Controller extends Controller {
  public function index($id) {
    $paths = unserialize(module::get_var("server_add", "authorized_paths"));

    $item = ORM::factory("item", $id);
    access::required("server_add", $item);

    $view = new View("server_add_tree_dialog.html");
    $view->action = url::site("__ARGS__/{$id}__TASK_ID__?csrf=" . access::csrf_token());
    $view->parents = $item->parents();
    $view->album_title = $item->title;

    $tree = new View("server_add_tree.html");
    $tree->data = array();
    $tree->tree_id = "tree_$id";
    foreach (array_keys($paths) as $path) {
      $tree->data[$path] = array("path" => $path, "is_dir" => true);
    }
    $view->tree = $tree->__toString();
    print $view;
  }

  public function children() {
    $paths = unserialize(module::get_var("server_add", "authorized_paths"));

    $path_valid = false;
    $path = $this->input->post("path");

    foreach (array_keys($paths) as $valid_path) {
      if ($path_valid = strpos($path, $valid_path) === 0) {
        break;
      }
    }
    if (empty($path_valid)) {
      throw new Exception("@todo BAD_PATH");
    }

    if (!is_readable($path) || is_link($path)) {
      kohana::show_404();
    }

    $tree = new View("server_add_tree.html");
    $tree->data = $this->_get_children($path);
    $tree->tree_id = "tree_" . md5($path);
    print $tree;
  }

  function start($id) {
    access::verify_csrf();
    $files = array();
    foreach ($this->input->post("path") as $path) {
      if (is_dir($path)) {
        $dir = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path), true);
        foreach ($dir as $file) {
          $extension = strtolower(substr(strrchr($file->getFilename(), '.'), 1));
          if ($file->isReadable() &&
              in_array($extension, array("gif", "jpeg", "jpg", "png", "flv", "mp4"))) {
            $files[] = $file->getPathname();
          }
        }
      } else {
        $files[] = $path;
      }
    }

    $task_def = Task_Definition::factory()
      ->callback("server_add_task::add_from_server")
      ->description(t("Add photos or movies from the local server"))
      ->name(t("Add from server"));
    $task = task::create($task_def, array("item_id" => $id, "next" => 0, "paths" => $files));

    batch::start();
    print json_encode(array("result" => "started",
                            "url" => url::site("server_add/add_photo/{$task->id}?csrf=" .
                                               access::csrf_token()),
                            "task" => $task->as_array()));
  }

  function add_photo($task_id) {
    access::verify_csrf();

    $task = task::run($task_id);

    if ($task->done) {
      switch ($task->state) {
      case "success":
        message::success(t("Add from server completed"));
        break;

      case "error":
        message::success(t("Add from server completed with errors"));
        break;
      }
      print json_encode(array("result" => "success",
                              "task" => $task->as_array()));

    } else {
      print json_encode(array("result" => "in_progress",
                              "task" => $task->as_array()));
    }
  }

  public function finish($id, $task_id) {
    batch::stop();
    print json_encode(array("result" => "success"));
  }

  private function _get_children($path) {
    $file_list = array();
    $files = new DirectoryIterator($path);
    foreach ($files as $file) {
      if ($file->isDot() || $file->isLink()) {
        continue;
      }
      $filename = $file->getFilename();
      if ($filename[0] != ".") {
        if ($file->isDir()) {
          $file_list[$filename] = array("path" => $file->getPathname(), "is_dir" => true);
        } else {
          $extension = strtolower(substr(strrchr($filename, '.'), 1));
          if ($file->isReadable() &&
              in_array($extension, array("gif", "jpeg", "jpg", "png", "flv", "mp4"))) {
            $file_list[$filename] = array("path" => $file->getPathname(), "is_dir" => false);
          }
        }
      }
    }
    return $file_list;
  }
}