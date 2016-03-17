# Eloquent OAuth L5

> Note: Use the [Laravel 4 package](https://github.com/adamwathan/eloquent-oauth-l4) if you are using Laravel 4.

Eloquent OAuth is a package for Laravel 5 designed to make authentication against various OAuth providers *ridiculously* brain-dead simple. Specify your client IDs and secrets in a config file, run a migration and after that it's just two method calls and you have OAuth integration.

#### Video Walkthrough

[![Screenshot](https://cloud.githubusercontent.com/assets/4323180/6274884/ac824c48-b848-11e4-8e4d-531e15f76bc0.png)](https://vimeo.com/120085196)

#### Basic example

```php
// Redirect to Facebook for authorization
Route::get('facebook/authorize', function() {
    return SocialAuth::authorize('facebook');
});

// Facebook redirects here after authorization
Route::get('facebook/login', function() {
    
    // Automatically log in existing users
    // or create a new user if necessary.
    SocialAuth::login('facebook');

    // Current user is now available via Auth facade
    $user = Auth::user();

    return Redirect::intended();
});
```

#### Supported Providers

- Facebook
- GitHub
- Google
- LinkedIn
- Instagram
- Soundcloud
- Custom providers

>Feel free to open an issue if you would like support for a particular provider, or even better, submit a pull request.

## Installation

#### Add this package using Composer

From the command line inside your project directory, simply type:

`composer require adamwathan/eloquent-oauth-l5`

#### Update your config

Add the service provider to the `providers` array in `config/app.php`:

```php
'providers' => [
    // ...
    'AdamWathan\EloquentOAuthL5\EloquentOAuthServiceProvider',
    // ...
]
```

Add the facade to the `aliases` array in `config/app.php`:

```php
'aliases' => [
    // ...
    'SocialAuth' => 'AdamWathan\EloquentOAuth\Facades\OAuth',
    // ...
]
```

#### Publish the package configuration

Publish the configuration file and migrations by running the provided console command:

`php artisan eloquent-oauth:install`

Next, re-migrate your database:

`php artisan migrate`

> If you need to change the name of the table used to store OAuth identities, you can do so in the `eloquent-oauth` config file.

#### Configure the providers

Update your app information for the providers you are using in `config/eloquent-oauth.php`:

```php
'providers' => [
    'facebook' => [
        'client_id' => '12345678',
        'client_secret' => 'y0ur53cr374ppk3y',
        'redirect_uri' => 'https://example.com/facebook/login'),
        'scope' => [],
    ]
]
```

> Each provider is preconfigured with the scope necessary to retrieve basic user information and the user's email address, so the scope array can usually be left empty unless you need specific additional permissions. Consult the provider's API documentation to find out what permissions are available for the various services.

All done!

> Eloquent OAuth is designed to integrate with Laravel's Eloquent authentication driver, so be sure you are using the `eloquent` driver in `app/config/auth.php`. You can define your actual `User` model however you choose and add whatever behavior you need, just be sure to specify the model you are using with its fully qualified namespace in `app/config/auth.php` as well.

## Usage

Authentication against an OAuth provider is a multi-step process, but I have tried to simplify it as much as possible.

### Authorizing with the provider

First you will need to define the authorization route. This is the route that your "Login" button will point to, and this route redirects the user to the provider's domain to authorize your app. After authorization, the provider will redirect the user back to your second route, which handles the rest of the authentication process.

To authorize the user, simply return the `SocialAuth::authorize()` method directly from the route.

```php
Route::get('facebook/authorize', function() {
    return SocialAuth::authorize('facebook');
});
```

### Authenticating within your app

Next you need to define a route for authenticating against your app with the details returned by the provider.

For basic cases, you can simply call `SocialAuth::login()` with the provider name you are authenticating with. If the user
rejected your application, this method will throw an `ApplicationRejectedException` which you can catch and handle
as necessary.

The `login` method will create a new user if necessary, or update an existing user if they have already used your application
before.

Once the `login` method succeeds, the user will be authenticated and available via `Auth::user()` just like if they
had logged in through your application normally.

```php
use SocialNorm\Exceptions\ApplicationRejectedException;
use SocialNorm\Exceptions\InvalidAuthorizationCodeException;

Route::get('facebook/login', function() {
    try {
        SocialAuth::login('facebook');
    } catch (ApplicationRejectedException $e) {
        // User rejected application
    } catch (InvalidAuthorizationCodeException $e) {
        // Authorization was attempted with invalid
        // code,likely forgery attempt
    }

    // Current user is now available via Auth facade
    $user = Auth::user();

    return Redirect::intended();
});
```

If you need to do anything with the newly created user, you can pass an optional closure as the second
argument to the `login` method. This closure will receive the `$user` instance and a `ProviderUserDetails`
object that contains basic information from the OAuth provider, including:

- User ID
- Nickname
- Full Name
- Email
- Avatar URL
- Access Token

```php
SocialAuth::login('facebook', function($user, $details) {
    $user->nickname = $details->nickname;
    $user->name = $details->full_name;
    $user->profile_image = $details->avatar;
    $user->save();
});
```

> Note: The Instagram and Soundcloud APIs do not allow you to retrieve the user's email address, so unfortunately that field will always be `null` for those providers.

### Advanced: Storing additional data

Remember: One of the goals of the Eloquent OAuth package is to normalize the data received across all supported providers, so that you can count on those specific data items (explained above) being available in the `$details` object.

But, each provider offers its own sets of additional data. If you need to access or store additional data beyond the basics of what Eloquent OAuth's default `ProviderUserDetails` object supplies, you need to do two things:

1. Request it from the provider, by extending its scope:

   Say for example we want to collect the user's gender when they login using Facebook.
   
   In the `config/eloquent-oauth.php` file, set the `[scope]` in the `facebook` provider section to include the `public_profile` scope, like this:
   
   ```php
      'scope' => ['public_profile'],
   ```
   
 > For available scopes with each provider, consult that provider's API documentation.

 > Note: By increasing the scope you will be asking the user to grant access to additional information. They will be informed of the scopes you're requesting. If you ask for too much unnecessary data, they may refuse. So exercise restraint when requesting additional scopes.

2. Now where you do your `SocialAuth::login`, store the to your `$user` object by accessing the `$details->raw()['KEY']` data:

 ```php
        SocialAuth::login('facebook', function($user, $details) (
            $user->gender = $details->raw()['gender'];
            $user->save();
        });
 ```
 
 > Tip: You can see what the available keys are by testing with `dd($details->raw());` inside that same closure.


#### Adding Custom Providers

You can add a custom provider in two (or three) steps.

1. Create a new custom service provider class.

   The easiest way to do this is to simply copy on of the built-in providers (eg. Facebook, Google, etc) and modify it for your needs. Usually you only have to change the URLs, but you may have to fiddle with the specifics a bit. Save this new provider into the Providers folder in your Laravel 5 application, and add it to the 'providers' array in your config/app.php.

2. Add a custom provider definition to config/eloquent-oauth.php.

   You will need to create a new element called 'custom-providers' at the same level as 'providers'. This has exactly the same syntax as a normal provider configuration, with the addition of the 'provider_class' element. This should contain the fully-qualified name of your custom provider class. For example:

   ```php

    return [
		
        'model' => User::class,
        'table' => 'oauth_identities',

   	//NOTE: if you are registering a custom provider, you MUST supply the 'provider_class' attribute 
	'custom-providers' => [
	    'myawesomeprovider' => [
		'client_id' => '12345678',
		'client_secret' => 'y0ur53cr374ppk3y',
		'redirect_uri' => 'http://mydomain.com/myawesomeprovider/callback',
		'scope' => [],
		'provider_class' => App\Providers\OAuth2\MyAwesomeOAuth2Provider::class 
	    ],
	],
	
	
	///these are the standard eloquent-oauth providers. 
        'providers' => [
            'facebook' => [
                'client_id' => '12345678',
                'client_secret' => 'b15e62b21638d8db571be1dbbf1a186d',
                'redirect_uri' => 'http://oauth-client.scotchbox.local/facebook/login',
                'scope' => [],
            ],

        ...
   ```

3. Add routes to App\Http\routes.php

   You can hard-code your custom routes, or you can use a wildcard to match any and all routes:

   ```php

	//OAuth authorization requests acts as login
	Route::get('{provider}/authorize', function($provider) {
            return SocialAuth::authorize($provider);
	});
		
	//OAuth redirects here after authorization
	Route::get('{provider}/callback', function($provider) {

	    // Automatically log in existing users
	    // or create a new user if necessary.
	    SocialAuth::login($provider, function($user, $details) {
			
	        //populate the user class.
                //this will be saved automatically by eloquent-oauth.
                $user->name = $details->nickname;
                $user->email = $details->email;
		//etc...
		    		
            });	  
		
	    return Redirect::intended();

	});

   ```

   Note that to use this, your 'redirect_uri' in 'config/eloquent-oauth.php' MUST match the provider name. For example, you must use the form http://domain/PROVIDERNAME/callback.
