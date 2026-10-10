<?php
declare(strict_types=1);

namespace EasyIT\LicenseServer\Admin;

use EasyIT\LicenseServer\Service\AuditService;
use EasyIT\LicenseServer\Support\Id;
use PDO;

final class AdminAuth
{
    private array $adminCfg;

    public function __construct(private PDO $pdo, private array $cfg, private AuditService $audit)
    {
        $base=(string)($cfg['app']['base_path'] ?? dirname(__DIR__,2));
        $this->adminCfg=$cfg['admin'] ?? [];
        $this->adminCfg += [
            'session_name'=>'EASYITLICADMIN',
            'idle_timeout_seconds'=>1800,
            'max_failed_logins'=>5,
            'lock_seconds'=>900,
            'setup_token_file'=>$base.'/secure/admin-setup.token',
        ];
    }

    public function startSession(): void
    {
        if(session_status()===PHP_SESSION_ACTIVE) return;
        session_name((string)$this->adminCfg['session_name']);
        $secure=(!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS'])!=='off') || ((string)($_SERVER['SERVER_PORT']??'')==='443');
        session_set_cookie_params([
            'lifetime'=>0,
            'path'=>'/',
            'secure'=>$secure,
            'httponly'=>true,
            'samesite'=>'Strict',
        ]);
        ini_set('session.use_only_cookies','1');
        ini_set('session.use_strict_mode','1');
        session_start();
        if(empty($_SESSION['admin_csrf'])) $_SESSION['admin_csrf']=bin2hex(random_bytes(32));
    }

    public function hasAdmins(): bool
    {
        $row=$this->pdo->query("SELECT COUNT(*) c FROM admin_users WHERE status IN ('active','disabled')")->fetch();
        $has=(int)($row['c']??0)>0;
        if($has){$file=(string)$this->adminCfg['setup_token_file'];if(is_file($file))@unlink($file);}
        return $has;
    }

    public function setupTokenConfigured(): bool
    {
        return $this->ensureSetupToken()!=='';
    }

    public function setup(string $token,string $username,string $password,string $displayName): void
    {
        if($this->hasAdmins()) throw new \RuntimeException('ADMIN_ALREADY_CONFIGURED');
        $expected=$this->setupToken();
        if($expected==='' || !hash_equals($expected,$token)) throw new \RuntimeException('ADMIN_SETUP_TOKEN_INVALID');
        $username=trim($username);$displayName=trim($displayName);
        if(!preg_match('/^[A-Za-z0-9._@-]{3,120}$/',$username)) throw new \RuntimeException('ADMIN_USERNAME_INVALID');
        $this->assertPassword($password);
        if($displayName==='') $displayName=$username;
        $now=time();$id=Id::make('ADM');
        $st=$this->pdo->prepare('INSERT INTO admin_users (admin_id,username,password_hash,display_name,role,status,failed_attempts,locked_until,last_login_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $st->execute([$id,$username,password_hash($password,PASSWORD_DEFAULT),$displayName,'superadmin','active',0,null,null,$now,$now]);
        $this->audit->write('ADMIN_CREATED','admin',$id,'system','bootstrap',null,[],['username'=>$username,'role'=>'superadmin']);
        $file=(string)$this->adminCfg['setup_token_file'];
        if(is_file($file)) @unlink($file);
    }

    /**
     * Vertrauenswuerdiger lokaler Ein-Seiten-Installer. Der HTTP-Endpunkt selbst
     * muss den Loopback-Zugriff pruefen. Es wird nur der allererste Admin angelegt.
     */
    public function setupTrustedLocal(string $username,string $password,string $displayName): void
    {
        if($this->hasAdmins()) throw new \RuntimeException('ADMIN_ALREADY_CONFIGURED');
        $username=trim($username);$displayName=trim($displayName);
        if(!preg_match('/^[A-Za-z0-9._@-]{3,120}$/',$username)) throw new \RuntimeException('ADMIN_USERNAME_INVALID');
        $this->assertPassword($password);
        if($displayName==='') $displayName=$username;
        $now=time();$id=Id::make('ADM');
        $st=$this->pdo->prepare('INSERT INTO admin_users (admin_id,username,password_hash,display_name,role,status,failed_attempts,locked_until,last_login_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $st->execute([$id,$username,password_hash($password,PASSWORD_DEFAULT),$displayName,'superadmin','active',0,null,null,$now,$now]);
        $this->audit->write('ADMIN_CREATED','admin',$id,'system','local_setup',null,[],['username'=>$username,'role'=>'superadmin']);
        $file=(string)$this->adminCfg['setup_token_file'];
        if(is_file($file)) @unlink($file);
    }

    public function login(string $username,string $password): bool
    {
        $username=trim($username);
        $st=$this->pdo->prepare('SELECT * FROM admin_users WHERE username=? LIMIT 1');
        $st->execute([$username]);$row=$st->fetch();$now=time();
        if(!$row){password_verify($password,'$2y$10$3NQ2k.5H0X7gYPQLWspvMO6vhkcwuPd0szC4yR9QkwpJ9cAtx9iD2');return false;}
        if((string)$row['status']!=='active') return false;
        if(!empty($row['locked_until']) && $now<(int)$row['locked_until']) return false;
        if(!password_verify($password,(string)$row['password_hash'])){
            $failed=(int)$row['failed_attempts']+1;$locked=null;
            if($failed >= (int)$this->adminCfg['max_failed_logins']){$locked=$now+(int)$this->adminCfg['lock_seconds'];$failed=0;}
            $up=$this->pdo->prepare('UPDATE admin_users SET failed_attempts=?,locked_until=?,updated_at=? WHERE admin_id=?');
            $up->execute([$failed,$locked,$now,$row['admin_id']]);
            $this->audit->write('ADMIN_LOGIN_FAILED','admin',(string)$row['admin_id'],'admin',(string)$row['admin_id']);
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['admin_user']=[
            'id'=>(string)$row['admin_id'],
            'username'=>(string)$row['username'],
            'display_name'=>(string)$row['display_name'],
            'role'=>(string)$row['role'],
        ];
        $_SESSION['admin_last_activity']=$now;
        $_SESSION['admin_csrf']=bin2hex(random_bytes(32));
        $up=$this->pdo->prepare('UPDATE admin_users SET failed_attempts=0,locked_until=NULL,last_login_at=?,updated_at=? WHERE admin_id=?');
        $up->execute([$now,$now,$row['admin_id']]);
        $this->audit->write('ADMIN_LOGIN','admin',(string)$row['admin_id'],'admin',(string)$row['admin_id']);
        return true;
    }

    public function logout(): void
    {
        // Do not call user() here. user() invokes logout() when an idle session
        // expires; calling user() from logout() therefore caused unbounded
        // recursion and exhausted PHP memory. Read the session identity directly.
        $u=$_SESSION['admin_user']??null;
        if(is_array($u) && isset($u['id'])) {
            $this->audit->write('ADMIN_LOGOUT','admin',(string)$u['id'],'admin',(string)$u['id']);
        }
        $_SESSION=[];
        if(ini_get('session.use_cookies')){
            $p=session_get_cookie_params();
            setcookie(session_name(),'',time()-42000,$p['path'],$p['domain']??'',(bool)$p['secure'],(bool)$p['httponly']);
        }
        session_destroy();
    }

    public function user(): ?array
    {
        $u=$_SESSION['admin_user']??null;
        if(!is_array($u)) return null;
        $now=time();$last=(int)($_SESSION['admin_last_activity']??0);
        if($last>0 && $now-$last>(int)$this->adminCfg['idle_timeout_seconds']){
            $this->logout();
            return null;
        }
        $_SESSION['admin_last_activity']=$now;
        return $u;
    }

    public function requireUser(): array
    {
        $u=$this->user();if(!$u) throw new \RuntimeException('ADMIN_LOGIN_REQUIRED');return $u;
    }

    public function csrf(): string{return (string)($_SESSION['admin_csrf']??'');}

    public function verifyCsrf(string $value): void
    {
        $expected=$this->csrf();
        if($expected==='' || $value==='' || !hash_equals($expected,$value)) throw new \RuntimeException('ADMIN_CSRF_INVALID');
    }

    public function flash(string $type,string $message): void
    {
        $_SESSION['admin_flash'][]=['type'=>$type,'message'=>$message];
    }

    public function consumeFlash(): array
    {
        $f=$_SESSION['admin_flash']??[];unset($_SESSION['admin_flash']);return is_array($f)?$f:[];
    }

    public function putSecretOnce(string $key,string $value): void{$_SESSION['admin_secret_once'][$key]=$value;}
    public function takeSecretOnce(string $key): ?string
    {
        $v=$_SESSION['admin_secret_once'][$key]??null;unset($_SESSION['admin_secret_once'][$key]);return is_string($v)?$v:null;
    }

    private function setupToken(): string
    {
        $direct=trim((string)($this->adminCfg['setup_token']??''));
        if($direct!=='') return $direct;
        $file=(string)$this->adminCfg['setup_token_file'];
        if(is_file($file)){
            $token=trim((string)file_get_contents($file));
            return strlen($token)>=32?$token:'';
        }
        return '';
    }

    /**
     * HF86 Phase 5: create the one-time bootstrap secret locally on first use.
     * The secret is deliberately generated on the installed server and is never
     * part of a release archive. Exclusive creation prevents concurrent requests
     * from replacing a token that another administrator may already have read.
     */
    private function ensureSetupToken(): string
    {
        $existing=$this->setupToken();
        if($existing!=='') return $existing;
        if(trim((string)($this->adminCfg['setup_token']??''))!=='') return '';

        $file=(string)$this->adminCfg['setup_token_file'];
        if($file==='') return '';
        if(is_file($file)) return ''; // existing but invalid/too short: fail closed

        $dir=dirname($file);
        if(!is_dir($dir) && !@mkdir($dir,0700,true) && !is_dir($dir)) return '';
        if(!is_writable($dir)) return '';

        // Defense in depth for Apache installations. Root .htaccess already
        // blocks secure/, but a local deny file keeps the bootstrap secret dark
        // if the directory is moved or the outer rule changes later.
        $deny=$dir.'/.htaccess';
        if(!is_file($deny)) @file_put_contents($deny,"Require all denied\nDeny from all\n",LOCK_EX);

        $token=bin2hex(random_bytes(32));
        $handle=@fopen($file,'x');
        if($handle===false){
            // Another request may have won the exclusive-create race.
            return $this->setupToken();
        }
        $ok=false;
        try{
            $written=fwrite($handle,$token.PHP_EOL);
            if($written!==false){fflush($handle);$ok=true;}
        }finally{
            fclose($handle);
        }
        if(!$ok){@unlink($file);return '';}
        @chmod($file,0600);
        return $token;
    }

    private function assertPassword(string $password): void
    {
        if(strlen($password)<10) throw new \RuntimeException('ADMIN_PASSWORD_TOO_SHORT');
        if(!preg_match('/[A-Z]/',$password)||!preg_match('/[a-z]/',$password)||!preg_match('/[0-9]/',$password)) throw new \RuntimeException('ADMIN_PASSWORD_WEAK');
    }
}
