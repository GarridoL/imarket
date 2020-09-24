<?php

namespace Increment\Imarket\Reward;

use Illuminate\Support\ServiceProvider;

class RewardServiceProvider extends ServiceProvider{

  public function boot(){
    $this->loadMigrationsFrom(__DIR__.'/migrations');
    $this->loadRoutesFrom(__DIR__.'/routes/web.php');
  }

  public function register(){
  }
}