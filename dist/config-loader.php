<?php
/** Server-only dotenv reader. No interpolation, shell evaluation, or browser exposure. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'&&!defined('KHUSHRANG_INTERNAL')){http_response_code(404);exit;}
final class KhushrangConfigError extends RuntimeException {
    public function __construct(public readonly string $errorCode,string $message){parent::__construct($message);}
}
function khushrang_config_path(string $publicDirectory): string {
    $explicit=getenv('KHUSHRANG_CONFIG');if($explicit!==false&&$explicit!=='')return $explicit;
    $env=getenv('KHUSHRANG_ENV_FILE');if($env!==false&&$env!=='')return dirname($env).'/config.php';
    // Root/subdomain and /admin deployments use different depths. Search only named
    // private siblings outside public_html/document root, never arbitrary .env files.
    $directory=dirname($publicDirectory);
    $documentRoot=realpath($_SERVER['DOCUMENT_ROOT']??'')?:null;
    for($i=0;$i<6;$i++){
        $private=$directory.'/khushrang-private';
        $resolved=realpath($private)?:$private;
        $publicPath=preg_match('~(?:^|/)public_html(?:/|$)~',$resolved)||($documentRoot&&($resolved===$documentRoot||str_starts_with($resolved,$documentRoot.'/')));
        if(!$publicPath&&(is_file($private.'/.env')||is_file($private.'/config.php')))return $private.'/config.php';
        $parent=dirname($directory);if($parent===$directory)break;$directory=$parent;
    }
    return dirname($publicDirectory).'/khushrang-private/config.php';
}
function khushrang_failure(Throwable $error): array {
    if($error instanceof KhushrangConfigError)return [$error->errorCode,$error->getMessage(),503];
    if($error instanceof PDOException){
        $driver=(int)($error->errorInfo[1]??0);
        if($driver===1045||$driver===1044)return ['DB_ACCESS_DENIED','Database access was rejected. Check DB_USER, DB_PASSWORD, DB_NAME and the database user permissions in Hostinger.',503];
        if($driver===1049)return ['DB_NOT_FOUND','The configured database does not exist. Check DB_NAME in the private .env.',503];
        if(in_array($driver,[2002,2003,2005],true))return ['DB_UNREACHABLE','Cannot connect to MySQL. Check DB_HOST and DB_PORT in the private .env.',503];
        if(($error->errorInfo[0]??$error->getCode())==='42S02')return ['DB_TABLES_MISSING','Required database tables are missing. Check that .env selects the database where database-setup.sql was imported.',503];
        if(($error->errorInfo[0]??$error->getCode())==='42S22')return ['DB_SCHEMA_MISMATCH','The database columns do not match this panel version. Check the users table structure against database-setup.sql.',503];
        if(str_contains($error->getMessage(),'could not find driver'))return ['PHP_MYSQL_MISSING','PHP PDO MySQL is unavailable. Enable pdo_mysql in Hostinger PHP settings.',503];
    }
    return ['SERVER_ERROR','The server could not complete the request. Check the private PHP log for this error code.',500];
}
function khushrang_read_env(string $path): array {
    if(!is_file($path))return [];
    if(!is_readable($path))throw new KhushrangConfigError('ENV_NOT_READABLE','The private .env file is not readable by PHP. Check its permissions in Hostinger.');
    $lines=file($path,FILE_IGNORE_NEW_LINES);if($lines===false)throw new KhushrangConfigError('ENV_NOT_READABLE','PHP could not read the private .env file.');
    $values=[];
    foreach($lines as $number=>$line){
        $line=trim($line);if($line===''||str_starts_with($line,'#'))continue;
        if(!preg_match('/^(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/',$line,$m))throw new KhushrangConfigError('ENV_FORMAT_INVALID','Invalid .env syntax at line '.($number+1).'.');
        $value=$m[2];$first=$value[0]??'';
        if($first==='"'||$first==="'"){
            if(strlen($value)<2||substr($value,-1)!==$first)throw new KhushrangConfigError('ENV_FORMAT_INVALID','Unclosed .env quote at line '.($number+1).'.');
            $value=substr($value,1,-1);
            if($first==='"')$value=strtr($value,['\\\\'=>'\\','\\"'=>'"','\\n'=>"\n",'\\r'=>"\r",'\\t'=>"\t"]);
        } else {
            // Inline comments require preceding whitespace. A literal # in a token is preserved.
            $value=rtrim(preg_replace('/\s+#.*$/','',$value));
        }
        if(array_key_exists($m[1],$values))throw new KhushrangConfigError('ENV_FORMAT_INVALID','A setting is duplicated in the private .env.');
        $values[$m[1]]=$value;
    }
    return $values;
}
function khushrang_load_config(string $configPath): array {
    $envPath=getenv('KHUSHRANG_ENV_FILE')?:dirname($configPath).'/.env';
    if(!is_file($configPath)&&!is_file($envPath))throw new KhushrangConfigError('CONFIG_MISSING','Private configuration was not found. Put .env in khushrang-private outside the site public folder, or set KHUSHRANG_ENV_FILE to its absolute path.');
    $config=is_file($configPath)?require $configPath:[];
    if(!is_array($config))throw new KhushrangConfigError('CONFIG_INVALID','Private config.php must return an array.');
    $file=khushrang_read_env($envPath);
    // Runtime variables take precedence, followed by .env, followed by legacy config.php.
    $read=static function(string $key)use($file):?string{$runtime=getenv($key);return $runtime!==false?$runtime:($file[$key]??null);};
    $dbKeys=['DB_HOST','DB_PORT','DB_NAME','DB_USER','DB_PASSWORD'];$hasDb=false;
    foreach($dbKeys as $key)if($read($key)!==null)$hasDb=true;
    if($hasDb){
        $host=$read('DB_HOST')??'localhost';$port=$read('DB_PORT')??'3306';$name=$read('DB_NAME');$user=$read('DB_USER');$password=$read('DB_PASSWORD');
        if(!preg_match('/^[A-Za-z0-9.:-]+$/',$host))throw new KhushrangConfigError('DB_CONFIG_INVALID','DB_HOST is invalid. Use the MySQL hostname without a URL scheme or port.');
        if(!preg_match('/^[0-9]+$/',$port)||(int)$port<1||(int)$port>65535)throw new KhushrangConfigError('DB_CONFIG_INVALID','DB_PORT must be a valid numeric port.');
        if($name===null||!preg_match('/^[A-Za-z0-9_-]+$/',$name))throw new KhushrangConfigError('DB_CONFIG_INVALID','DB_NAME is missing or invalid in the private .env.');
        if($user===null||$user==='')throw new KhushrangConfigError('DB_CONFIG_INVALID','DB_USER is required in the private .env.');
        if($password===null)throw new KhushrangConfigError('DB_CONFIG_INVALID','DB_PASSWORD is required in the private .env.');
        $config['dsn']='mysql:host='.$host.';port='.$port.';dbname='.$name.';charset=utf8mb4';$config['user']=$user;$config['password']=$password;
    }
    $storage=$read('STORAGE_PATH');if($storage!==null)$config['storage_path']=$storage;
    if(empty($config['storage_path'])||!str_starts_with($config['storage_path'],'/'))throw new KhushrangConfigError('STORAGE_CONFIG_INVALID','STORAGE_PATH must be an absolute server filesystem path, not a URL.');
    foreach(['dsn','user','password'] as $key)if(!array_key_exists($key,$config))throw new KhushrangConfigError('DB_CONFIG_INVALID','Database configuration is incomplete. Check DB_HOST, DB_PORT, DB_NAME, DB_USER and DB_PASSWORD.');
    $config['private_directory']=dirname($envPath);
    $config['inbound_connectors']??=[];$config['email_destination']??=[];
    return $config;
}
