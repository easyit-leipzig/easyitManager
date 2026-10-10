<?php
declare(strict_types=1);

namespace DataForm5\Core\Dashboard;

use DataForm5\Core\Filesystem\StorageManager;
use DataForm5\Modules\Background\ModuleBackgroundAdmin;
use DataForm5\Modules\Core\ModuleManager;
use DataForm5\Monitoring\Core\HealthManager;
use DataForm5\Cluster\Core\ClusterManager;
use DataForm5\Replication\Core\ClusterSynchronizer;

final class EnterpriseDashboard
{
    public function __construct(
        private readonly ModuleManager $modules,
        private readonly ModuleBackgroundAdmin $background,
        private readonly HealthManager $monitoring,
        private readonly ClusterManager $cluster,
        private readonly StorageManager $storage,
        private readonly ClusterSynchronizer $replication
    ) {}

    public function snapshot(): array
    {
        $result=[
            'generated_at'=>date(DATE_ATOM),
            'modules'=>['status'=>'unknown','active'=>0,'total'=>0,'issues'=>0],
            'jobs'=>['status'=>'unknown','pending'=>0,'failed'=>0],
            'monitoring'=>['status'=>'unknown','alerts'=>0],
            'cluster'=>['status'=>'unknown','online'=>0,'nodes'=>0,'leader'=>null],
            'storage'=>['status'=>'unknown','healthy'=>0,'total'=>0],
            'replication'=>['status'=>'unknown','channel'=>'','security'=>false],
        ];

        try{
            $rows=$this->modules->status();
            $active=count(array_filter($rows,static fn(array $r):bool=>(bool)($r['enabled']??false)));
            $issues=count(array_filter($rows,static fn(array $r):bool=>($r['missing_dependencies']??[])!==[]));
            $result['modules']=['status'=>$issues===0?'healthy':'degraded','active'=>$active,'total'=>count($rows),'issues'=>$issues];
        }catch(\Throwable $e){
            $result['modules']['status']='failed';
        }

        try{
            $state=$this->background->overview('file');
            $pending=(int)($state['stats']['pending']??0);
            $failed=(int)($state['stats']['failed']??0);
            $result['jobs']=['status'=>$failed>0?'degraded':'healthy','pending'=>$pending,'failed'=>$failed];
        }catch(\Throwable $e){
            $result['jobs']['status']='failed';
        }

        try{
            $state=$this->monitoring->snapshot(false);
            $alerts=is_array($state['alerts']??null)?count($state['alerts']):0;
            $result['monitoring']=['status'=>(string)($state['status']??'unknown'),'alerts'=>$alerts];
        }catch(\Throwable $e){
            $result['monitoring']['status']='failed';
        }

        try{
            $state=$this->cluster->health();
            $result['cluster']=[
                'status'=>(string)($state['status']??'unknown'),
                'online'=>(int)($state['online_count']??0),
                'nodes'=>(int)($state['node_count']??0),
                'leader'=>$state['leader']['id']??null,
            ];
        }catch(\Throwable $e){
            $result['cluster']['status']='failed';
        }

        try{
            $rows=$this->storage->health();
            $healthy=count(array_filter($rows,static fn(array $r):bool=>(string)($r['status']??'')==='healthy'));
            $failed=count(array_filter($rows,static fn(array $r):bool=>in_array((string)($r['status']??''),['failed','degraded'],true)));
            $result['storage']=['status'=>$failed===0?'healthy':'degraded','healthy'=>$healthy,'total'=>count($rows)];
        }catch(\Throwable $e){
            $result['storage']['status']='failed';
        }

        try{
            $state=$this->replication->health();
            $transport=(string)($state['transport']['storage']['status']??'unknown');
            $result['replication']=[
                'status'=>$transport,
                'channel'=>(string)($state['channel']??''),
                'security'=>(bool)($state['security_enabled']??false),
            ];
        }catch(\Throwable $e){
            $result['replication']['status']='failed';
        }

        $statuses=array_column($result,'status');
        $statuses=array_filter($statuses,'is_string');
        $result['overall']=in_array('failed',$statuses,true)
            ? 'failed'
            : (count(array_intersect($statuses,['degraded','down']))>0?'degraded':'healthy');

        return $result;
    }
}
