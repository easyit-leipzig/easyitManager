<?php
declare(strict_types=1);
namespace EasyIT\LicenseServer\Service;

use EasyIT\LicenseServer\Support\Id;
use PDO;

final class CustomerRoleService
{
    private const INTERNAL=['account.users.manage','account.roles.manage','account.projects.manage','account.installations.manage'];

    public function __construct(private PDO $pdo,private AuditService $audit){}

    public function ensureDefaults(string $customerId): void
    {
        $now=time();
        $defs=[
            'customer_admin'=>['Kundenadministrator',['*','account.users.manage','account.roles.manage','account.projects.manage','account.installations.manage']],
            'editor'=>['Bearbeiter',['dataset.read','dataset.create','dataset.update','dataform.search','dataform.filter','dataform.pagination','relation.read','relation.createChild','relation.attach','relation.detach']],
            'viewer'=>['Leser',['dataset.read','dataform.search','dataform.filter','dataform.pagination','relation.read']],
        ];
        foreach($defs as $name=>[$label,$caps]){
            $st=$this->pdo->prepare('SELECT role_id FROM customer_roles WHERE customer_id=? AND role_name=? LIMIT 1');
            $st->execute([$customerId,$name]);
            $rid=(string)($st->fetchColumn()?:'');
            if($rid===''){
                $rid=Id::make('CROLE');
                $this->pdo->prepare("INSERT INTO customer_roles (role_id,customer_id,role_name,label,is_system,status,created_at,updated_at) VALUES (?,?,?,?,1,'active',?,?)")->execute([$rid,$customerId,$name,$label,$now,$now]);
                $ins=$this->pdo->prepare('INSERT INTO customer_role_capabilities (role_id,capability_code,created_at) VALUES (?,?,?)');
                foreach($caps as $cap)$ins->execute([$rid,$cap,$now]);
            }
            if($name==='customer_admin'){
                $q=$this->pdo->prepare('SELECT COUNT(*) FROM customer_role_capabilities WHERE role_id=? AND capability_code=?');$q->execute([$rid,'account.installations.manage']);
                if((int)$q->fetchColumn()===0)$this->pdo->prepare('INSERT INTO customer_role_capabilities (role_id,capability_code,created_at) VALUES (?,?,?)')->execute([$rid,'account.installations.manage',$now]);
            }
            $this->ensureRoleScopeRow($rid,'unrestricted');
        }

        $users=$this->pdo->prepare('SELECT customer_user_id,role FROM customer_users WHERE customer_id=?');
        $users->execute([$customerId]);
        foreach($users->fetchAll() as $u){
            $uid=(string)$u['customer_user_id'];
            $q=$this->pdo->prepare('SELECT COUNT(*) FROM customer_user_roles WHERE customer_user_id=?');
            $q->execute([$uid]);
            if((int)$q->fetchColumn()===0){
                $legacy=(string)($u['role']??'viewer');
                $target=in_array($legacy,['customer_admin','editor','viewer'],true)?$legacy:($legacy==='admin'?'customer_admin':'viewer');
                $r=$this->pdo->prepare('SELECT role_id FROM customer_roles WHERE customer_id=? AND role_name=?');
                $r->execute([$customerId,$target]);
                if($rid=$r->fetchColumn())$this->pdo->prepare('INSERT INTO customer_user_roles (customer_user_id,role_id,created_at) VALUES (?,?,?)')->execute([$uid,$rid,$now]);
            }
            $this->ensureUserScopeRow($uid,'inherit');
        }
    }

    public function policy(string $customerId,string $userId,array $licensePolicy,string $licenseId=''): array
    {
        $this->ensureDefaults($customerId);
        $st=$this->pdo->prepare("SELECT r.role_id,r.role_name,r.label,rc.capability_code FROM customer_user_roles ur JOIN customer_roles r ON r.role_id=ur.role_id LEFT JOIN customer_role_capabilities rc ON rc.role_id=r.role_id WHERE ur.customer_user_id=? AND r.customer_id=? AND r.status='active' ORDER BY r.role_name");
        $st->execute([$userId,$customerId]);
        $roles=[];$caps=[];
        foreach($st->fetchAll() as $r){
            $roles[(string)$r['role_id']]=['id'=>(string)$r['role_id'],'name'=>(string)$r['role_name'],'label'=>(string)$r['label']];
            if($r['capability_code']!==null)$caps[]=(string)$r['capability_code'];
        }
        $caps=array_values(array_unique($caps));
        $wild=in_array('*',$caps,true);
        $internal=array_values(array_intersect($caps,self::INTERNAL));
        $roleProduct=array_values(array_filter($caps,fn($c)=>$c!=='*'&&!str_starts_with($c,'account.')));
        $legacy=(string)($licensePolicy['capability_policy']??'')==='legacy_unrestricted';
        $licenseCaps=array_values(array_map('strval',(array)($licensePolicy['capabilities']??[])));
        $effective=$wild?($legacy?['*']:$licenseCaps):($legacy?$roleProduct:array_values(array_intersect($roleProduct,$licenseCaps)));
        sort($effective);sort($internal);

        $roleIds=array_keys($roles);
        $resource=$this->effectiveResourceScope($customerId,$userId,$roleIds,$licenseId);
        return ['roles'=>array_values($roles),'unrestricted'=>$wild,'capabilities'=>$effective,'internal_capabilities'=>$internal,'resource_scope'=>$resource];
    }

    public function assertInternal(string $customerId,string $userId,array $licensePolicy,string $cap,string $licenseId=''): void
    {
        $p=$this->policy($customerId,$userId,$licensePolicy,$licenseId);
        if(!in_array($cap,(array)$p['internal_capabilities'],true))throw new \RuntimeException('ACCOUNT_ROLE_PERMISSION_DENIED');
    }

    public function team(string $customerId,string $actorUserId,array $licensePolicy,string $licenseId): array
    {
        $p=$this->policy($customerId,$actorUserId,$licensePolicy,$licenseId);
        $internal=(array)($p['internal_capabilities']??[]);
        if(!in_array('account.users.manage',$internal,true)&&!in_array('account.roles.manage',$internal,true))throw new \RuntimeException('ACCOUNT_ROLE_PERMISSION_DENIED');
        $this->ensureDefaults($customerId);

        $rs=$this->pdo->prepare("SELECT role_id,role_name,label,is_system,status FROM customer_roles WHERE customer_id=? AND status='active' ORDER BY is_system DESC,label");
        $rs->execute([$customerId]);
        $roles=$rs->fetchAll();
        foreach($roles as &$r){
            $rid=(string)$r['role_id'];
            $q=$this->pdo->prepare('SELECT capability_code FROM customer_role_capabilities WHERE role_id=? ORDER BY capability_code');
            $q->execute([$rid]);
            $r['capabilities']=array_map(fn($x)=>(string)$x['capability_code'],$q->fetchAll());
            $r['resource_scope']=$this->roleScope($rid,(string)$r['role_name']);
        }
        unset($r);

        $us=$this->pdo->prepare('SELECT customer_user_id,email,username,display_name,status,last_login_at,created_at FROM customer_users WHERE customer_id=? ORDER BY display_name,email');
        $us->execute([$customerId]);
        $users=$us->fetchAll();
        foreach($users as &$u){
            $uid=(string)$u['customer_user_id'];
            $q=$this->pdo->prepare('SELECT r.role_id,r.role_name,r.label FROM customer_user_roles ur JOIN customer_roles r ON r.role_id=ur.role_id WHERE ur.customer_user_id=? AND r.status=\'active\' ORDER BY r.label');
            $q->execute([$uid]);
            $u['roles']=$q->fetchAll();
            $u['resource_scope']=$this->userScope($uid);
        }
        unset($u);

        $caps=$this->pdo->query('SELECT capability_code,name,category FROM capability_registry WHERE active=1 ORDER BY category,capability_code')->fetchAll();
        return [
            'roles'=>$roles,
            'users'=>$users,
            'capability_catalog'=>$caps,
            'internal_catalog'=>[
                ['capability_code'=>'account.projects.manage','name'=>'Projekte verwalten','category'=>'account'],
                ['capability_code'=>'account.users.manage','name'=>'Benutzer verwalten','category'=>'account'],
                ['capability_code'=>'account.roles.manage','name'=>'Rollen verwalten','category'=>'account'],
                ['capability_code'=>'account.installations.manage','name'=>'Installationen stilllegen','category'=>'account'],
            ],
            'resource_catalog'=>$this->resourceCatalog($customerId,$licenseId),
        ];
    }

    public function createUser(array $actor,array $licensePolicy,array $data,string $licenseId): array
    {
        $cid=(string)$actor['customer_id'];
        $this->assertInternal($cid,(string)$actor['customer_user_id'],$licensePolicy,'account.users.manage',$licenseId);
        $email=strtolower(trim((string)($data['email']??'')));$name=trim((string)($data['display_name']??''));$pw=(string)($data['password']??'');
        if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new \RuntimeException('ACCOUNT_EMAIL_INVALID');
        if(strlen($pw)<10||!preg_match('/[a-z]/',$pw)||!preg_match('/[A-Z]/',$pw)||!preg_match('/\d/',$pw))throw new \RuntimeException('ACCOUNT_PASSWORD_WEAK');
        $q=$this->pdo->prepare('SELECT COUNT(*) FROM customer_users WHERE LOWER(COALESCE(email,username))=?');$q->execute([$email]);if((int)$q->fetchColumn()>0)throw new \RuntimeException('ACCOUNT_ALREADY_EXISTS');
        $id=Id::make('CUSU');$now=time();
        $this->pdo->beginTransaction();
        try{
            $this->pdo->prepare("INSERT INTO customer_users (customer_user_id,customer_id,username,email,license_id,password_hash,display_name,role,status,failed_attempts,locked_until,last_login_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?, 'active',0,NULL,NULL,?,?)")->execute([$id,$cid,$email,$email,$licenseId,password_hash($pw,PASSWORD_DEFAULT),$name!==''?$name:$email,'viewer',$now,$now]);
            $this->assignRolesInternal($cid,$id,(array)($data['role_ids']??[]));
            $this->saveUserResourceScope($cid,$licenseId,$id,(string)($data['resource_scope_mode']??'inherit'),(array)($data['project_ids']??[]),(array)($data['dataform_uuids']??[]));
            $this->pdo->commit();
        }catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        $this->audit->write('CUSTOMER_TEAM_USER_CREATED','customer_user',$id,'customer_user',(string)$actor['customer_user_id'],null,[],['email'=>$email]);
        return ['created'=>true,'customer_user_id'=>$id];
    }

    public function setUser(array $actor,array $licensePolicy,array $data,string $licenseId): array
    {
        $cid=(string)$actor['customer_id'];
        $this->assertInternal($cid,(string)$actor['customer_user_id'],$licensePolicy,'account.users.manage',$licenseId);
        $uid=(string)($data['customer_user_id']??'');
        if($uid==='')throw new \RuntimeException('ACCOUNT_USER_REQUIRED');
        $status=(string)($data['status']??'active');
        if(!in_array($status,['active','disabled'],true))throw new \RuntimeException('STATUS_INVALID');
        if($uid===(string)$actor['customer_user_id'])throw new \RuntimeException('ACCOUNT_CANNOT_CHANGE_SELF');
        $q=$this->pdo->prepare('SELECT COUNT(*) FROM customer_users WHERE customer_user_id=? AND customer_id=?');$q->execute([$uid,$cid]);if((int)$q->fetchColumn()!==1)throw new \RuntimeException('ACCOUNT_USER_NOT_FOUND');
        $this->pdo->beginTransaction();
        try{
            $this->pdo->prepare('UPDATE customer_users SET status=?,updated_at=? WHERE customer_user_id=? AND customer_id=?')->execute([$status,time(),$uid,$cid]);
            $this->assignRolesInternal($cid,$uid,(array)($data['role_ids']??[]));
            if(array_key_exists('resource_scope_mode',$data))$this->saveUserResourceScope($cid,$licenseId,$uid,(string)$data['resource_scope_mode'],(array)($data['project_ids']??[]),(array)($data['dataform_uuids']??[]));
            $this->pdo->commit();
        }catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        $this->audit->write('CUSTOMER_TEAM_USER_UPDATED','customer_user',$uid,'customer_user',(string)$actor['customer_user_id'],null,[],['resource_scope_mode'=>(string)($data['resource_scope_mode']??'inherit')]);
        return ['updated'=>true];
    }

    public function resetUserPassword(array $actor,array $licensePolicy,array $data,string $licenseId): array
    {
        $cid=(string)$actor['customer_id'];
        $this->assertInternal($cid,(string)$actor['customer_user_id'],$licensePolicy,'account.users.manage',$licenseId);
        $uid=(string)($data['customer_user_id']??'');if($uid==='')throw new \RuntimeException('ACCOUNT_USER_REQUIRED');
        if($uid===(string)$actor['customer_user_id'])throw new \RuntimeException('ACCOUNT_PASSWORD_USE_SELF_SERVICE');
        $svc=new CustomerCredentialService($this->pdo,$this->audit);
        $version=$svc->resetPassword($cid,$uid,(string)($data['new_password']??''),(string)($data['new_password_confirm']??''),'customer_user',(string)$actor['customer_user_id']);
        return ['password_reset'=>true,'sessions_revoked'=>true,'credential_version'=>$version];
    }

    public function saveRole(array $actor,array $licensePolicy,array $data,string $licenseId): array
    {
        $cid=(string)$actor['customer_id'];
        $this->assertInternal($cid,(string)$actor['customer_user_id'],$licensePolicy,'account.roles.manage',$licenseId);
        $rid=(string)($data['role_id']??'');$name=strtolower(trim((string)($data['role_name']??'')));$label=trim((string)($data['label']??''));
        $caps=array_values(array_unique(array_map('strval',(array)($data['capabilities']??[]))));$isNew=$rid==='';
        if(!preg_match('/^[a-z][a-z0-9_.-]{2,49}$/',$name))throw new \RuntimeException('ACCOUNT_ROLE_NAME_INVALID');
        if($label==='')throw new \RuntimeException('ACCOUNT_ROLE_LABEL_REQUIRED');
        $now=time();$protected=false;
        $this->pdo->beginTransaction();
        try{
            if($rid!==''){
                $q=$this->pdo->prepare('SELECT role_name,is_system FROM customer_roles WHERE role_id=? AND customer_id=?');$q->execute([$rid,$cid]);$r=$q->fetch();if(!$r)throw new \RuntimeException('ACCOUNT_ROLE_NOT_FOUND');
                $protected=(int)$r['is_system']===1&&(string)$r['role_name']==='customer_admin';
                if($protected)throw new \RuntimeException('ACCOUNT_ADMIN_ROLE_PROTECTED');
                if((int)$r['is_system']===1)$name=(string)$r['role_name'];
                $this->pdo->prepare('UPDATE customer_roles SET role_name=?,label=?,updated_at=? WHERE role_id=? AND customer_id=?')->execute([$name,$label,$now,$rid,$cid]);
            }else{
                $rid=Id::make('CROLE');
                $this->pdo->prepare("INSERT INTO customer_roles (role_id,customer_id,role_name,label,is_system,status,created_at,updated_at) VALUES (?,?,?,?,0,'active',?,?)")->execute([$rid,$cid,$name,$label,$now,$now]);
            }
            $this->pdo->prepare('DELETE FROM customer_role_capabilities WHERE role_id=?')->execute([$rid]);
            $ins=$this->pdo->prepare('INSERT INTO customer_role_capabilities (role_id,capability_code,created_at) VALUES (?,?,?)');foreach($caps as $c)$ins->execute([$rid,$c,$now]);
            if($isNew||array_key_exists('resource_scope_mode',$data))$this->saveRoleResourceScope($cid,$licenseId,$rid,(string)($data['resource_scope_mode']??'unrestricted'),(array)($data['project_ids']??[]),(array)($data['dataform_uuids']??[]));
            $this->pdo->commit();
        }catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        $this->audit->write('CUSTOMER_ROLE_SAVED','customer_role',$rid,'customer_user',(string)$actor['customer_user_id'],null,[],['capabilities'=>$caps,'resource_scope_mode'=>(string)($data['resource_scope_mode']??'unrestricted')]);
        return ['saved'=>true,'role_id'=>$rid];
    }

    private function effectiveResourceScope(string $customerId,string $userId,array $roleIds,string $licenseId): array
    {
        $catalog=$this->resourceCatalog($customerId,$licenseId);
        $projectUniverse=array_values(array_unique(array_map(fn($p)=>(string)$p['project_id'],(array)$catalog['projects'])));
        $dfs=(array)$catalog['dataforms'];

        $roleUnrestricted=false;$roleProjects=[];$roleDfs=[];
        foreach($roleIds as $rid){
            $scope=$this->roleScope((string)$rid,'');
            if(($scope['mode']??'unrestricted')==='unrestricted'){$roleUnrestricted=true;break;}
            foreach((array)($scope['project_ids']??[]) as $v)$roleProjects[(string)$v]=true;
            foreach((array)($scope['dataform_uuids']??[]) as $v)$roleDfs[(string)$v]=true;
        }
        $userScope=$this->userScope($userId);
        $userRestricted=($userScope['mode']??'inherit')==='restricted';
        $userProjects=array_fill_keys(array_map('strval',(array)($userScope['project_ids']??[])),true);
        $userDfs=array_fill_keys(array_map('strval',(array)($userScope['dataform_uuids']??[])),true);

        if($roleUnrestricted&&!$userRestricted){
            return ['unrestricted'=>true,'mode'=>'unrestricted','project_ids'=>[],'visible_project_ids'=>[],'dataform_uuids'=>[],'dataforms'=>[]];
        }

        $projectIds=[];
        foreach($projectUniverse as $pid){
            $roleWhole=$roleUnrestricted||isset($roleProjects[$pid]);
            $userWhole=!$userRestricted||isset($userProjects[$pid]);
            if($roleWhole&&$userWhole)$projectIds[$pid]=true;
        }

        $allowedDfs=[];$dfUuids=[];$visibleProjects=$projectIds;
        foreach($dfs as $df){
            $pid=(string)$df['project_id'];$uuid=(string)$df['dataform_uuid'];
            $roleAllowed=$roleUnrestricted||isset($roleProjects[$pid])||isset($roleDfs[$uuid]);
            $userAllowed=!$userRestricted||isset($userProjects[$pid])||isset($userDfs[$uuid]);
            if(!$roleAllowed||!$userAllowed)continue;
            $dfUuids[$uuid]=true;$visibleProjects[$pid]=true;
            $allowedDfs[]=['dataform_uuid'=>$uuid,'project_id'=>$pid,'local_dataform_id'=>(string)$df['local_dataform_id']];
        }
        $pids=array_keys($projectIds);sort($pids);$vpids=array_keys($visibleProjects);sort($vpids);$duids=array_keys($dfUuids);sort($duids);
        return ['unrestricted'=>false,'mode'=>'restricted','project_ids'=>$pids,'visible_project_ids'=>$vpids,'dataform_uuids'=>$duids,'dataforms'=>$allowedDfs];
    }

    private function resourceCatalog(string $customerId,string $licenseId): array
    {
        if($licenseId==='')return ['projects'=>[],'dataforms'=>[]];
        $st=$this->pdo->prepare("SELECT dataform_uuid,project_id,local_dataform_id,name,slug,status FROM license_dataforms WHERE customer_id=? AND license_id=? AND status='approved' ORDER BY project_id,name,local_dataform_id");
        $st->execute([$customerId,$licenseId]);
        $dfs=$st->fetchAll();$projects=[];
        foreach($dfs as $df){$pid=(string)$df['project_id'];if(!isset($projects[$pid]))$projects[$pid]=['project_id'=>$pid,'label'=>'Projekt '.$pid,'dataform_count'=>0];$projects[$pid]['dataform_count']++;}
        return ['projects'=>array_values($projects),'dataforms'=>$dfs];
    }

    private function roleScope(string $roleId,string $roleName=''): array
    {
        if($roleName==='customer_admin')return ['mode'=>'unrestricted','project_ids'=>[],'dataform_uuids'=>[]];
        $st=$this->pdo->prepare('SELECT scope_mode FROM customer_role_resource_scopes WHERE role_id=? LIMIT 1');$st->execute([$roleId]);$mode=(string)($st->fetchColumn()?:'unrestricted');
        if(!in_array($mode,['unrestricted','restricted'],true))$mode='unrestricted';
        $p=$this->pdo->prepare('SELECT project_id FROM customer_role_projects WHERE role_id=? ORDER BY project_id');$p->execute([$roleId]);
        $d=$this->pdo->prepare('SELECT dataform_uuid FROM customer_role_dataforms WHERE role_id=? ORDER BY dataform_uuid');$d->execute([$roleId]);
        return ['mode'=>$mode,'project_ids'=>array_map('strval',$p->fetchAll(PDO::FETCH_COLUMN)),'dataform_uuids'=>array_map('strval',$d->fetchAll(PDO::FETCH_COLUMN))];
    }

    private function userScope(string $userId): array
    {
        $st=$this->pdo->prepare('SELECT scope_mode FROM customer_user_resource_scopes WHERE customer_user_id=? LIMIT 1');$st->execute([$userId]);$mode=(string)($st->fetchColumn()?:'inherit');
        if(!in_array($mode,['inherit','restricted'],true))$mode='inherit';
        $p=$this->pdo->prepare('SELECT project_id FROM customer_user_projects WHERE customer_user_id=? ORDER BY project_id');$p->execute([$userId]);
        $d=$this->pdo->prepare('SELECT dataform_uuid FROM customer_user_dataforms WHERE customer_user_id=? ORDER BY dataform_uuid');$d->execute([$userId]);
        return ['mode'=>$mode,'project_ids'=>array_map('strval',$p->fetchAll(PDO::FETCH_COLUMN)),'dataform_uuids'=>array_map('strval',$d->fetchAll(PDO::FETCH_COLUMN))];
    }

    private function saveRoleResourceScope(string $customerId,string $licenseId,string $roleId,string $mode,array $projectIds,array $dataformUuids): void
    {
        if(!in_array($mode,['unrestricted','restricted'],true))throw new \RuntimeException('ACCOUNT_RESOURCE_SCOPE_INVALID');
        [$projectIds,$dataformUuids]=$this->validatedResources($customerId,$licenseId,$projectIds,$dataformUuids);
        $this->pdo->prepare('DELETE FROM customer_role_resource_scopes WHERE role_id=?')->execute([$roleId]);
        $this->pdo->prepare('INSERT INTO customer_role_resource_scopes (role_id,scope_mode,updated_at) VALUES (?,?,?)')->execute([$roleId,$mode,time()]);
        $this->pdo->prepare('DELETE FROM customer_role_projects WHERE role_id=?')->execute([$roleId]);
        $this->pdo->prepare('DELETE FROM customer_role_dataforms WHERE role_id=?')->execute([$roleId]);
        if($mode==='restricted'){
            $pi=$this->pdo->prepare('INSERT INTO customer_role_projects (role_id,project_id,created_at) VALUES (?,?,?)');foreach($projectIds as $pid)$pi->execute([$roleId,$pid,time()]);
            $di=$this->pdo->prepare('INSERT INTO customer_role_dataforms (role_id,dataform_uuid,created_at) VALUES (?,?,?)');foreach($dataformUuids as $uuid)$di->execute([$roleId,$uuid,time()]);
        }
    }

    private function saveUserResourceScope(string $customerId,string $licenseId,string $userId,string $mode,array $projectIds,array $dataformUuids): void
    {
        if(!in_array($mode,['inherit','restricted'],true))throw new \RuntimeException('ACCOUNT_RESOURCE_SCOPE_INVALID');
        [$projectIds,$dataformUuids]=$this->validatedResources($customerId,$licenseId,$projectIds,$dataformUuids);
        $this->pdo->prepare('DELETE FROM customer_user_resource_scopes WHERE customer_user_id=?')->execute([$userId]);
        $this->pdo->prepare('INSERT INTO customer_user_resource_scopes (customer_user_id,scope_mode,updated_at) VALUES (?,?,?)')->execute([$userId,$mode,time()]);
        $this->pdo->prepare('DELETE FROM customer_user_projects WHERE customer_user_id=?')->execute([$userId]);
        $this->pdo->prepare('DELETE FROM customer_user_dataforms WHERE customer_user_id=?')->execute([$userId]);
        if($mode==='restricted'){
            $pi=$this->pdo->prepare('INSERT INTO customer_user_projects (customer_user_id,project_id,created_at) VALUES (?,?,?)');foreach($projectIds as $pid)$pi->execute([$userId,$pid,time()]);
            $di=$this->pdo->prepare('INSERT INTO customer_user_dataforms (customer_user_id,dataform_uuid,created_at) VALUES (?,?,?)');foreach($dataformUuids as $uuid)$di->execute([$userId,$uuid,time()]);
        }
    }

    private function validatedResources(string $customerId,string $licenseId,array $projectIds,array $dataformUuids): array
    {
        $catalog=$this->resourceCatalog($customerId,$licenseId);
        $validProjects=array_fill_keys(array_map(fn($x)=>(string)$x['project_id'],(array)$catalog['projects']),true);
        $validDfs=array_fill_keys(array_map(fn($x)=>(string)$x['dataform_uuid'],(array)$catalog['dataforms']),true);
        $projectIds=array_values(array_unique(array_filter(array_map('strval',$projectIds),fn($v)=>isset($validProjects[$v]))));
        $dataformUuids=array_values(array_unique(array_filter(array_map('strval',$dataformUuids),fn($v)=>isset($validDfs[$v]))));
        sort($projectIds);sort($dataformUuids);
        return [$projectIds,$dataformUuids];
    }

    private function ensureRoleScopeRow(string $roleId,string $mode): void
    {
        $q=$this->pdo->prepare('SELECT COUNT(*) FROM customer_role_resource_scopes WHERE role_id=?');$q->execute([$roleId]);if((int)$q->fetchColumn()>0)return;
        $this->pdo->prepare('INSERT INTO customer_role_resource_scopes (role_id,scope_mode,updated_at) VALUES (?,?,?)')->execute([$roleId,$mode,time()]);
    }

    private function ensureUserScopeRow(string $userId,string $mode): void
    {
        $q=$this->pdo->prepare('SELECT COUNT(*) FROM customer_user_resource_scopes WHERE customer_user_id=?');$q->execute([$userId]);if((int)$q->fetchColumn()>0)return;
        $this->pdo->prepare('INSERT INTO customer_user_resource_scopes (customer_user_id,scope_mode,updated_at) VALUES (?,?,?)')->execute([$userId,$mode,time()]);
    }

    private function assignRolesInternal(string $customerId,string $userId,array $roleIds): void
    {
        $roleIds=array_values(array_unique(array_filter(array_map('strval',$roleIds))));
        if(!$roleIds){$q=$this->pdo->prepare('SELECT role_id FROM customer_roles WHERE customer_id=? AND role_name=\'viewer\'');$q->execute([$customerId]);if($v=$q->fetchColumn())$roleIds=[(string)$v];}
        $valid=[];$q=$this->pdo->prepare("SELECT role_id FROM customer_roles WHERE role_id=? AND customer_id=? AND status='active'");
        foreach($roleIds as $rid){$q->execute([$rid,$customerId]);if($q->fetchColumn())$valid[]=$rid;}
        if(!$valid)throw new \RuntimeException('ACCOUNT_ROLE_REQUIRED');
        $this->pdo->prepare('DELETE FROM customer_user_roles WHERE customer_user_id=?')->execute([$userId]);
        $ins=$this->pdo->prepare('INSERT INTO customer_user_roles (customer_user_id,role_id,created_at) VALUES (?,?,?)');foreach($valid as $rid)$ins->execute([$userId,$rid,time()]);
    }
}
