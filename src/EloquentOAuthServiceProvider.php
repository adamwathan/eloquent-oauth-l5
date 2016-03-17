<?php namespace AdamWathan\EloquentOAuthL5;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use GuzzleHttp\Client as HttpClient;
use SocialNorm\SocialNorm;
use SocialNorm\ProviderRegistry;
use SocialNorm\Request;
use SocialNorm\StateGenerator;
use SocialNorm\Exceptions\ProviderNotRegisteredException;
use AdamWathan\EloquentOAuth\Authenticator;
use AdamWathan\EloquentOAuth\EloquentIdentityStore;
use AdamWathan\EloquentOAuth\IdentityStore;
use AdamWathan\EloquentOAuth\Session;
use AdamWathan\EloquentOAuth\OAuthIdentity;
use AdamWathan\EloquentOAuth\OAuthManager;
use AdamWathan\EloquentOAuth\UserStore;
use AdamWathan\EloquentOAuth\Facades\OAuth;


class EloquentOAuthServiceProvider extends ServiceProvider {

    protected $providerLookup = [
        'facebook' => 'SocialNorm\Facebook\FacebookProvider',
        'github' => 'SocialNorm\GitHub\GitHubProvider',
        'google' => 'SocialNorm\Google\GoogleProvider',
        'linkedin' => 'SocialNorm\LinkedIn\LinkedInProvider',
        'instagram' => 'SocialNorm\Instagram\InstagramProvider',
        'soundcloud' => 'SocialNorm\SoundCloud\SoundCloudProvider',
    ];

    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = false;

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->configureOAuthIdentitiesTable();
        $this->registerIdentityStore();
        $this->registerOAuthManager();
        $this->registerCommands();
    }

    protected function registerIdentityStore()
    {
        $this->app->singleton('AdamWathan\EloquentOAuth\IdentityStore', function ($app) {
            return new EloquentIdentityStore;
        });
    }
    
    protected function registerOAuthManager()
    {
        $this->app['adamwathan.oauth'] = $this->app->share(function ($app) {
            $providerRegistry = new ProviderRegistry;
            $session = $this->getSession($app);
            $request = $this->getRequest($app);
            $stateGenerator = new StateGenerator;
            $socialnorm = new SocialNorm($providerRegistry, $session, $request, $stateGenerator);
            
            //register built-in providers
            $this->registerProviders($socialnorm, $request);
            
            //register custom providers
            $this->registerCustomProviders($socialnorm, $request);

            if ($app['config']['eloquent-oauth.model']) {
                $users = new UserStore($app['config']['eloquent-oauth.model']);
            } else {
                if (starts_with($app->version(), '5.2')) {
                    $users = new UserStore($app['config']['auth.providers.users.model']);
                } else {
                    $users = new UserStore($app['config']['auth.model']);
                }
            }

            $authenticator = new Authenticator(
                $app['Illuminate\Contracts\Auth\Guard'],
                $users,
                $app['AdamWathan\EloquentOAuth\IdentityStore']
            );

            $oauth = new OAuthManager($app['redirect'], $authenticator, $socialnorm);
            return $oauth;
        });
    }
    
    protected function registerProviders($socialnorm, $request)
    {
        if (! $providerAliases = $this->app['config']['eloquent-oauth.providers']) {
            return;
        }

        foreach ($providerAliases as $alias => $config) {
            if (isset($this->providerLookup[$alias])) {
                $providerClass = $this->providerLookup[$alias];
                $provider = new $providerClass($config, $this->getHttpClient(), $request);
                $socialnorm->registerProvider($alias, $provider);
            }
        }
    }

    /**
     * Register any custom providers found in config/eloquent-oauth.php.
     *
     * Custom providers must be registered under 'custom-providers', which is a sibling of the 'providers' element.
     * The custom-providers child elements follow the same syntax as the 'providers' elements, but must include a
     * 'provider_class' element in order to contruct the custom provider.
     *
     * @author Tyson LT
     */
    protected function registerCustomProviders($socialnorm, $request) {
    	 
    	//loop over list of custom providers, if any
    	foreach ($this->getCustomProviderConfig() as $alias => $config) {

   			//get the custom provider class name
   			$providerClass = $this->getCustomProviderClass($config);
    			
   			//did the developer provide a custom class?
   			if (null == $providerClass) {
    				 
   				//no provider class found, tell dev how to configure
   				throw new ProviderNotRegisteredException("Custom provide '$alias' does not have a 'provider_class' element in config/eloquent-auth.php");
    				 
   			} else if (!class_exists($providerClass)) {
   			
   				//class does not exist, so give developer a handy hint
   				throw new ProviderNotRegisteredException("Could not construct '$providerClass' [class_exists() failed] for custom provider '$alias'.");
   				
   			}
    				
			//create our custom provider
			$provider = new $providerClass($config, $this->getHttpClient(), $request);
    				
			//register provider with OAuth container
			$socialnorm->registerProvider($alias, $provider);
    				
			Log::debug("Registered custom OAuth provider '$alias' as '$providerClass'");
    			 
   		} //end foreach: custom providers

    }
        
    /**
     * Load custom providers array from config, if present.
     * 
     * @author Tyson LT
     * @return Provider config array if defined, or empty array.
     */
    protected function getCustomProviderConfig() {
    	if (isset($this->app['config']['eloquent-oauth']['custom-providers'])) {
    		return $this->app['config']['eloquent-oauth']['custom-providers'];
    	} else {
    		return [];
    	}
    }
    
    /**
     * Get the custom provider class, if any.
     * 
     * @param array $config
     * @author TysonLT
     */
    protected function getCustomProviderClass($config) {
    	if (empty($config['provider_class'])) {
    		return null;
    	} else {
    		return $config['provider_class'];
    	}
    }
    
    /**
     * Get the Laravel request.
     * @author Tyson LT
     */
    protected function getRequest($app) {
    	return new Request($app['request']->all());
    }
    
    /**
     * Get the Laravel session.
     * @author Tyson LT
     */
    protected function getSession($app) {
    	return new Session($app['session']);
    }
    
    /**
     * Create HttpClient
     * @author Tyson LT
     */
    protected function getHttpClient() {
    	return new HttpClient();
    }
    
    protected function configureOAuthIdentitiesTable()
    {
        OAuthIdentity::configureTable($this->app['config']['eloquent-oauth.table']);
    }

    /**
     * Registers some utility commands with artisan
     * @return void
     */
    public function registerCommands()
    {
        $this->app->bind('command.eloquent-oauth.install', 'AdamWathan\EloquentOAuthL5\Installation\InstallCommand');
        $this->commands('command.eloquent-oauth.install');
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return ['adamwathan.oauth'];
    }

}
