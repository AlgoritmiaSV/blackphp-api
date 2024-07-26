<?php
trait Users
{
	/**
	 * Cargar tabla de usuarios
	 * 
	 * Devuelve, en formato JSON o en un archivo Excel, la lista de usuarios.
	 * @param string $response El modo de respuesta (JSON o Excel)
	 * 
	 * @return void
	 */
	public function user_table_loader($response = "JSON")
	{
		$this->check_permissions("read", "users");
		$data = Array();
		$users = userDataModel::getAllArray();
		foreach($users as $key => $user)
		{
			$users[$key]["last_login"] = "";
			if(!empty($user["last_login"]))
			{
				$last_login = new DateTime($user["last_login"]);
				$users[$key]["last_login"] = $last_login->format("d/m/Y h:ia");
			}
		}
		$data["content"] = $users;
		if($response == "Excel")
		{
			$data["title"] = _("Users");
			$data["headers"] = Array(_("User"), _("Complete name"), _("Last login"));
			$data["fields"] = Array("nickname", "user_name", "last_login");
			excel::create_from_table($data, "Users_" . Date("YmdHis") . ".xlsx");
		}
		else
		{
			$this->json($data);
		}
	}

	/**
	 * Carga de detalles del usuario
	 * 
	 * Muestra una hoja con los detalles del usuario. Este método puede ser invocado por a través
	 * de UserDetails (embedded) y directamente para ser mostrado en un jAlert (standalone); por
	 * ejemplo, para el usuario con ID 1, se podría visitar:
	 * - Settings/UserDetails/1/ (embedded)
	 * - Settings/user_details_loader/1/standalone/ (standalone)
	 * @param int $user_id ID del usuario
	 * @param string $mode Modo en que se mostrará la vista
	 * 
	 * @return void
	 */
	public function user_details_loader($user_id = "", $mode = "embedded")
	{
		$this->check_permissions("read", "users", $mode);
		if(empty($user_id))
		{
			$user_id = $_POST["id"];
		}
		$user = userDataModel::findBy("user_id", $user_id)->toArray();
		$this->view->data = array_merge($this->view->data, $user);
		$sessions = userSessionsModel::join("browsers", "browser_id")->where("user_sessions.user_id", $user_id)->orderBy("date_time", "DESC")->addCounter("item")->get(10);
		foreach($sessions as $key => $session)
		{
			$time = strtotime($session["date_time"]);
			$sessions[$key]["session_date"] = Date("d/m/Y", $time);
			$sessions[$key]["session_time"] = Date("h:i a", $time);
		}
		$this->view->data["sessions"] = $sessions;

		#User photo
		$photo = glob("entities/" . $this->entity_subdomain . "/users/profile_" . $user["user_id"] . ".*");
		if(count($photo) > 0)
		{
			$this->view->data["user_photo"] = $photo[0];
		}
		else
		{
			$this->view->data["user_photo"] = "public/images/user.png";
		}
		$this->userActions($user);
		if($user_id == Session::get("user_id"))
		{
			$this->view->restrict[] = "no_self";
		}
		$this->view->data["print_title"] = _("User details");
		$this->view->data["print_header"] = $this->view->render("print_header", true);
		if($mode == "standalone")
		{
			$this->view->data["title"] = _("User details");
			$this->view->standard_details();
			$this->view->add("styles", "css", Array(
				'styles/standalone.css'
			));
			$this->view->restrict[] = "embedded";
			$this->view->data["content"] = $this->view->render('settings/user_details', true);
			$this->view->render('clean_main');
		}
		else
		{
			$this->view->render("settings/user_details");
		}
	}

	/**
	 * Guardar usuario
	 * 
	 * Crea o actualiza un usuario en la base de datos, asignándole permisos a cada módulo
	 * y a cada método seleccionado en el formulario.
	 * 
	 * @return void
	 */
	public function save_user()
	{
		$this->check_permissions(empty($_POST["user_id"]) ? "create" : "update", "users");
		$data = Array("success" => false);
		if(empty($_POST["user_name"]))
		{
			$this->json($data);
			return;
		}

		#Validate nickname
		$test = usersModel::where("nickname", $_POST["nickname"])
			->where("user_id", "!=", $_POST["user_id"])->get();
		if(!empty($test->getUserId()))
		{
			$data["title"] = "Error";
			$data["message"] = _("The nickname already exists!");
			$data["theme"] = "red";
			$this->json($data);
			return;
		}

		$user_id = 0;
		$user = usersModel::find($_POST["user_id"])
			->set(Array(
				"user_name" => $_POST["user_name"],
				"nickname" => $_POST["nickname"]
			));
		if(!empty($_POST["password"]))
		{
			$user->setPassword("HASH");
			$user->setPasswordHash(password_hash($_POST["password"], PASSWORD_BCRYPT));
		}
		if(empty($user->getPassword()))
		{
			$user->setPassword("");
			$user->setPasswordHash("");
		}
		if(!empty($_POST["role_id"]))
		{
			$user->setRoleId($_POST["role_id"]);
		}
		$user->save();
		if(!empty($_POST["user_id"]))
		{
			$this->setUserLog("update", "users", $user->getUserId());
		}
		else
		{
			$this->setUserLog("create", "users", $user->getUserId());
		}

		$user_id = $user->getUserId();
		if($user_id != Session::get("user_id"))
		{
			#Module access
			userModulesModel::where("user_id", $user_id)->whereNotIn($_POST["modules"], "module_id")->update(Array("status" => 0));
			foreach($_POST["modules"] as $module_id)
			{
				userModulesModel::where("user_id", $user_id)
					->where("module_id", $module_id)
					->where("status", ">=", 0)
					->get()->set(Array(
						"module_id" => $module_id,
						"user_id" => $user_id,
						"status" => 1
					))->save();
			}

			#Method access
			userMethodsModel::where("user_id", $user_id)->whereNotIn($_POST["methods"], "method_id")->update(Array("status" => 0));
			foreach($_POST["methods"] as $method_id)
			{
				userMethodsModel::where("user_id", $user_id)
					->where("method_id", $method_id)
					->where("status", ">=", 0)
					->get()->set(Array(
						"method_id" => $method_id,
						"user_id" => $user_id,
						"status" => 1
					))->save();
			}
		}
		$data["success"] = true;
		$data["title"] = _("Success");
		$data["message"] = _("Changes have been saved");
		$data["theme"] = "green";
		$data["reload_after"] = true;
		$this->json($data);
	}

	/**
	 * Eliminar usuario
	 * 
	 * Elimina un usuario e imprime la respuesta en formato JSON.
	 * 
	 * @return void
	 */
	public function delete_user()
	{
		$this->check_permissions("delete", "users");
		$data = Array("deleted" => false);
		if(empty($_POST["id"]))
		{
			$this->json($data);
			return;
		}
		$user = usersModel::find($_POST["id"]);
		$affected = $user->delete();
		$data["deleted"] = $affected > 0;
		$this->setUserLog("delete", "users", $user->getUserId());
		$this->json($data);
	}
}
?>
