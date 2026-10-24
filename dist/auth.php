<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'&&!defined('KHUSHRANG_INTERNAL')){http_response_code(404);exit;}
if($action==='me'){echo json_encode(auth_public_user($user));exit;}
if($action==='password_change'){
    if($_SERVER['REQUEST_METHOD']!=='POST')fail('POST required.',405);
    $input=json_decode(file_get_contents('php://input'),true)?:[];
    $old=$input['current_password']??'';if(!is_string($old)||!password_verify($old,$user['password_hash']))fail('Current password is incorrect.',403);
    $password=auth_password($input['new_password']??null);if(password_verify($password,$user['password_hash']))fail('Choose a different new password.');
    $q=$db->prepare('UPDATE users SET password_hash=?,must_change_password=0,session_version=session_version+1 WHERE id=? AND session_version=?');$q->execute([password_hash($password,PASSWORD_BCRYPT),$user['id'],$user['session_version']]);if(!$q->rowCount())fail('Account changed. Sign in again.',409);
    $_SESSION['session_version']=(int)$user['session_version']+1;session_regenerate_id(true);
    $user['must_change_password']=0;echo json_encode(auth_public_user($user));exit;
}
if((bool)$user['must_change_password'])fail('Change your temporary password before using the workspace.',403);
if(str_starts_with($action,'users_')){
    if(!auth_policy($user['role'])['users'])fail('Super Admin permission required.',403);
    if($action==='users_list'){
        if($_SERVER['REQUEST_METHOD']!=='GET')fail('GET required.',405);
        echo json_encode(['users'=>$db->query('SELECT id,name,email,role,active,must_change_password,created_at FROM users ORDER BY id')->fetchAll(),'roles'=>['super_admin','admin','operations','finance','viewer']]);exit;
    }
    if($action!=='users_save')fail('Unknown user action.',404);
    if($_SERVER['REQUEST_METHOD']!=='POST')fail('POST required.',405);
    $input=json_decode(file_get_contents('php://input'),true)?:[];
    $name=$input['name']??'';$email=$input['email']??'';$role=$input['role']??'';$active=$input['active']??true;
    if(!is_string($name)||trim($name)===''||strlen($name)>190)fail('A name up to 190 bytes is required.');
    if(!is_string($email)||strlen($email)>190||!filter_var($email,FILTER_VALIDATE_EMAIL))fail('A valid email is required.');
    if(!is_string($role)||!in_array($role,['super_admin','admin','operations','finance','viewer'],true))fail('Invalid role.');
    if(!is_bool($active))fail('active must be a boolean.');
    $id=$input['id']??null;if($id!==null&&(!is_scalar($id)||!preg_match('/^[1-9][0-9]*$/',(string)$id)))fail('Invalid user ID.');
    $password=$input['password']??'';if(!is_string($password))fail('Invalid password.');if($id===null||$password!=='')auth_password($password);
    if($id!==null&&(string)$id===(string)$user['id']&&(!$active||$role!=='super_admin'))fail('You cannot disable or demote your own Super Admin account.',409);
    $db->beginTransaction();
    try{
        // Serialize privilege-changing operations so two administrators cannot remove the last owner.
        $owners=$db->query("SELECT id FROM users WHERE role='super_admin' AND active=1 ORDER BY id FOR UPDATE")->fetchAll();
        $q=$db->prepare('SELECT id,name,email,role,active FROM users WHERE id=? FOR UPDATE');$q->execute([$id??0]);$target=$q->fetch();
        if($id!==null&&!$target){$db->rollBack();fail('User not found.',404);}
        if($target&&$target['role']==='super_admin'&&(bool)$target['active']&&(!$active||$role!=='super_admin')&&count($owners)<=1){$db->rollBack();fail('At least one active Super Admin must remain.',409);}
        if($id===null){
            $q=$db->prepare('INSERT INTO users(email,name,password_hash,role,active,must_change_password) VALUES(?,?,?,?,?,1)');$q->execute([strtolower(trim($email)),trim($name),password_hash($password,PASSWORD_BCRYPT),$role,(int)$active]);$id=$db->lastInsertId();
        }else{
            $changed=$target['role']!==$role||(bool)$target['active']!==$active||strtolower($target['email'])!==strtolower(trim($email))||$password!=='';
            $sql='UPDATE users SET name=?,email=?,role=?,active=?,session_version=session_version+?';$values=[trim($name),strtolower(trim($email)),$role,(int)$active,(int)$changed];
            if($password!==''){$sql.=',password_hash=?,must_change_password=1';$values[]=password_hash($password,PASSWORD_BCRYPT);}
            $sql.=' WHERE id=?';$values[]=$id;$q=$db->prepare($sql);$q->execute($values);
        }
        $db->commit();echo json_encode(['id'=>(string)$id]);exit;
    }catch(PDOException $e){if($db->inTransaction())$db->rollBack();if($e->getCode()==='23000')fail('That email is already in use.',409);throw $e;}
}
