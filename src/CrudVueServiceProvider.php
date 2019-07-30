<?php

namespace CrudVue;

use Illuminate\Support\ServiceProvider;

class CrudVueServiceProvider extends ServiceProvider
{
    public function boot()
    {
    	if ($this->app->runningInConsole()) {
	        $this->commands([
	            CrudVueCommand::class,
	        ]);
	    }
    }

    public function provides()
    {
        return [
            CrudVueCommand::class,
        ];
    }
}