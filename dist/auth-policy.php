<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'&&!defined('KHUSHRANG_INTERNAL')){http_response_code(404);exit;}
function auth_policy(string $role): array {
    $operations=['events','clients','vendors','tasks','media'];
    $all=['events','clients','vendors','invoices','payments','expenses','tasks','media'];
    return match($role){
        'super_admin'=>['read'=>$all,'write'=>$all,'users'=>true,'integrations'=>true],
        'admin'=>['read'=>$all,'write'=>$all,'users'=>false,'integrations'=>true],
        'operations'=>['read'=>$operations,'write'=>$operations,'users'=>false,'integrations'=>false],
        'finance'=>['read'=>['events','clients','invoices','payments','expenses'],'write'=>['invoices','payments','expenses'],'users'=>false,'integrations'=>false],
        'viewer'=>['read'=>$operations,'write'=>[],'users'=>false,'integrations'=>false],
        default=>['read'=>[],'write'=>[],'users'=>false,'integrations'=>false]
    };
}
function auth_public_user(array $user): array {
    return ['id'=>(string)$user['id'],'name'=>$user['name'],'email'=>$user['email'],'role'=>$user['role'],'must_change_password'=>(bool)$user['must_change_password'],'permissions'=>auth_policy($user['role'])];
}
function auth_require(array $user,string $resource,string $mode='read'): void {
    $p=auth_policy($user['role']);if(!in_array($resource,$p[$mode]??[],true))fail('You do not have permission for this operation.',403);
}
function auth_password(mixed $password): string {
    if(!is_string($password)||strlen($password)<12||strlen($password)>72)fail('Passwords must be 12–72 bytes long.');return $password;
}
