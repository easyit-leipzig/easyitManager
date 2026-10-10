<?php $view->extend('layouts.app'); $view->start('content'); ?>
<h1><?= $view->e($heading) ?></h1>
<?= $view->component('components.card',['title'=>'Sicher','content'=>$message]) ?>
<?php $view->end(); ?>
