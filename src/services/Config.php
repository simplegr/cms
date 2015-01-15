<?php
/**
 * @link http://buildwithcraft.com/
 * @copyright Copyright (c) 2013 Pixel & Tonic, Inc.
 * @license http://buildwithcraft.com/license
 */

namespace craft\app\services;

use Craft;
use craft\app\enums\ConfigCategory;
use craft\app\enums\LogLevel;
use craft\app\helpers\AppHelper;
use craft\app\helpers\ArrayHelper;
use craft\app\helpers\DateTimeHelper;
use craft\app\helpers\IOHelper;
use craft\app\helpers\StringHelper;
use craft\app\helpers\UrlHelper;
use craft\app\models\User as UserModel;
use yii\base\Component;

/**
 * The Config service provides APIs for retrieving the values of Craft’s [config settings](http://buildwithcraft.com/docs/config-settings),
 * as well as the values of any plugins’ config settings.
 *
 * An instance of the Config service is globally accessible in Craft via [[Application::config `Craft::$app->config`]].
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0
 */
class Config extends Component
{
	// Properties
	// =========================================================================

	/**
	 * @var
	 */
	private $_cacheDuration;

	/**
	 * @var
	 */
	private $_omitScriptNameInUrls;

	/**
	 * @var
	 */
	private $_usePathInfo;

	/**
	 * @var array
	 */
	private $_loadedConfigFiles = [];

	// Public Methods
	// =========================================================================

	/**
	 * Returns a config setting value by its name.
	 *
	 * If the config file is set up as a [multi-environment config](http://buildwithcraft.com/docs/multi-environment-configs),
	 * only values from config arrays that match the current request’s environment will be checked and returned.
	 *
	 * By default, `get()` will check craft/config/general.php, and fall back on the default values specified in
	 * craft/app/config/defaults/general.php. See [Craft’s documentation](http://buildwithcraft.com/docs/config-settings)
	 * for a full list of config settings that Craft will check for within that file.
	 *
	 * ```php
	 * $isDevMode = Craft::$app->config->get('devMode');
	 * ```
	 *
	 * If you want to get the config setting from a different config file (e.g. config/myplugin.php), you can specify
	 * its filename as a second argument. If the filename matches a plugin handle, `get()` will check for a
	 * craft/plugins/PluginHandle]/config.php file and use the array it returns as the list of default values.
	 *
	 * ```php
	 * $myConfigSetting = Craft::$app->config->get('myConfigSetting', 'myplugin');
	 * ```
	 *
	 * @param string $item The name of the config setting.
	 * @param string $category The name of the config file (sans .php). Defaults to 'general'.
	 *
	 * @return mixed The value of the config setting, or `null` if a value could not be found.
	 */
	public function get($item, $category = ConfigCategory::General)
	{
		if (!isset($this->_loadedConfigFiles[$category]))
		{
			$this->_loadConfigFile($category);
		}

		if ($this->exists($item, $category))
		{
			return $this->_loadedConfigFiles[$category][$item];
		}
	}

	/**
	 * Overrides the value of a config setting to a given value.
	 *
	 * By default, `set()` will update the config array that came from craft/config/general.php.
	 * See [Craft’s documentation](http://buildwithcraft.com/docs/config-settings)
	 * for a full list of config settings that Craft will check for within that file.
	 *
	 * ```php
	 * Craft::$app->config->set('devMode', true);
	 * ```
	 *
	 * If you want to set a config setting from a different config file (e.g. config/myplugin.php), you can specify
	 * its filename as a third argument.
	 *
	 * ```php
	 * Craft::$app->config->set('myConfigSetting', 'foo', 'myplugin');
	 * ```
	 *
	 * @param string $item  The name of the config setting.
	 * @param mixed  $value The new value of the config setting.
	 * @param string $category The name of the config file (sans .php). Defaults to 'general'.
	 *
	 * @return null
	 */
	public function set($item, $value, $category = ConfigCategory::General)
	{
		if (!isset($this->_loadedConfigFiles[$category]))
		{
			$this->_loadConfigFile($category);
		}

		$this->_loadedConfigFiles[$category][$item] = $value;
	}

	/**
	 * Returns a localized config setting value by its name.
	 *
	 * Internally, [[get()]] will be called to get the value of the config setting. If the value is an array,
	 * then only a single value in that array will be returned: the one that has a key matching the `$localeId` argument.
	 * If no matching key is found, the first element of the array will be returned instead.
	 *
	 * This function is used for Craft’s “localizable” config settings:
	 *
	 * - [siteUrl](http://buildwithcraft.com/docs/config-settings#siteUrl)
	 * - [invalidUserTokenPath](http://buildwithcraft.com/docs/config-settings#invalidUserTokenPath)
	 * - [loginPath](http://buildwithcraft.com/docs/config-settings#loginPath)
	 * - [logoutPath](http://buildwithcraft.com/docs/config-settings#logoutPath)
	 * - [setPasswordPath](http://buildwithcraft.com/docs/config-settings#setPasswordPath)
	 * - [setPasswordSuccessPath](http://buildwithcraft.com/docs/config-settings#setPasswordSuccessPath)
	 *
	 * @param string $item     The name of the config setting.
	 * @param string $localeId The locale ID to return. Defaults to
	 *                         [[\craft\app\web\Application::getLanguage() `Craft::$app->getLanguage()`]].
	 * @param string $category The name of the config file (sans .php). Defaults to 'general'.
	 *
	 * @return mixed The value of the config setting, or `null` if a value could not be found.
	 */
	public function getLocalized($item, $localeId = null, $category = ConfigCategory::General)
	{
		$value = $this->get($item, $category);

		if (is_array($value))
		{
			if (!$localeId)
			{
				$localeId = Craft::$app->language;
			}

			if (isset($value[$localeId]))
			{
				return $value[$localeId];
			}
			else if ($value)
			{
				// Just return the first value
				$keys = array_keys($value);
				return $value[$keys[0]];
			}
		}
		else
		{
			return $value;
		}
	}

	/**
	 * Returns whether a config setting value exists, by a given name.
	 *
	 * If the config file is set up as a [multi-environment config](http://buildwithcraft.com/docs/multi-environment-configs),
	 * only values from config arrays that match the current request’s environment will be checked.
	 *
	 * By default, `exists()` will check craft/config/general.php, and fall back on the default values specified in
	 * craft/app/config/defaults/general.php. See [Craft’s documentation](http://buildwithcraft.com/docs/config-settings)
	 * for a full list of config settings that Craft will check for within that file.
	 *
	 * If you want to get the config setting from a different config file (e.g. config/myplugin.php), you can specify
	 * its filename as a second argument. If the filename matches a plugin handle, `get()` will check for a
	 * craft/plugins/PluginHandle]/config.php file and use the array it returns as the list of default values.
	 *
	 * ```php
	 * if (Craft::$app->config->exists('myConfigSetting', 'myplugin'))
	 * {
	 *     Craft::log('This site has some pretty useless config settings.');
	 * }
	 * ```
	 *
	 * @param string $item The name of the config setting.
	 * @param string $category The name of the config file (sans .php). Defaults to 'general'.
	 *
	 * @return bool Whether the config setting value exists.
	 */
	public function exists($item, $category = ConfigCategory::General)
	{
		if (!isset($this->_loadedConfigFiles[$category]))
		{
			$this->_loadConfigFile($category);
		}

		if (array_key_exists($item, $this->_loadedConfigFiles[$category]))
		{
			return true;
		}

		return false;
	}

	/**
	 * Returns the value of the [cacheDuration](http://buildwithcraft.com/docs/config-settings#cacheDuration) config setting,
	 * normalized into seconds.
	 *
	 * The actual value of the cacheDuration config setting is supposed to be set using the
	 * [PHP interval specification](http://php.net/manual/en/dateinterval.construct.php).
	 *
	 * ```php
	 * Craft::$app->config->get('cacheDuration'); // 'P1D'
	 * Craft::$app->config->getCacheDuration();   // 86400
	 * ```
	 *
	 * @return int The cacheDuration config setting value, in seconds.
	 */
	public function getCacheDuration()
	{
		if (!isset($this->_cacheDuration))
		{
			$duration = $this->get('cacheDuration');

			if ($duration)
			{
				$this->_cacheDuration = DateTimeHelper::timeFormatToSeconds($duration);
			}
			else
			{
				$this->_cacheDuration = 0;
			}
		}

		return $this->_cacheDuration;
	}

	/**
	 * Returns whether generated URLs should omit “index.php”, taking the
	 * [omitScriptNameInUrls](http://buildwithcraft.com/docs/config-settings#omitScriptNameInUrls) config setting value
	 * into account.
	 *
	 * If the omitScriptNameInUrls config setting is set to `true` or `false`, then its value will be returned directly.
	 * Otherwise, `omitScriptNameInUrls()` will try to determine whether the server is set up to support index.php
	 * redirection. (See [this help article](http://buildwithcraft.com/help/remove-index.php) for instructions.)
	 *
	 * It does that by creating a dummy request to the site URL with the URI “/testScriptNameRedirect”. If the index.php
	 * redirect is in place, that request should be sent to Craft’s index.php file, which will detect that this is an
	 * index.php redirect-testing request, and simply return “success”. If anything besides “success” is returned
	 * (i.e. an Apache-styled 404 error), then Craft assumes the index.php redirect is not in fact in place.
	 *
	 * Results of the redirect test request will be cached for the amount of time specified by the
	 * [cacheDuration](http://buildwithcraft.com/docs/config-settings#cacheDuration) config setting.
	 *
	 * @return bool Whether generated URLs should omit “index.php”.
	 */
	public function omitScriptNameInUrls()
	{
		if (!isset($this->_omitScriptNameInUrls))
		{
			$this->_omitScriptNameInUrls = 'n';

			// Check if the config value has actually been set to true/false
			$configVal = $this->get('omitScriptNameInUrls');

			if (is_bool($configVal))
			{
				$this->_omitScriptNameInUrls = ($configVal === true ? 'y' : 'n');
			}
			else
			{
				// Check if it's cached
				$cachedVal = Craft::$app->getCache()->get('omitScriptNameInUrls');

				if ($cachedVal !== false)
				{
					$this->_omitScriptNameInUrls = $cachedVal;
				}
				else
				{
					// PHP Dev Server does omit the script name from 404s without any help from a redirect script,
					// *unless* the URI looks like a file, in which case it'll just throw a 404.
					if (AppHelper::isPhpDevServer())
					{
						$this->_omitScriptNameInUrls = false;
					}
					else
					{
						// Cache it early so the testScriptNameRedirect request isn't checking for it too
						Craft::$app->getCache()->set('omitScriptNameInUrls', 'n');

						// Test the server for it
						try
						{
							$baseUrl = Craft::$app->getRequest()->getHostInfo().Craft::$app->getRequest()->getScriptUrl();
							$url = mb_substr($baseUrl, 0, mb_strrpos($baseUrl, '/')).'/testScriptNameRedirect';

							$client = new \Guzzle\Http\Client();
							$response = $client->get($url, [], ['connect_timeout' => 2, 'timeout' => 4])->send();

							if ($response->isSuccessful() && $response->getBody(true) === 'success')
							{
								$this->_omitScriptNameInUrls = 'y';
							}
						}
						catch (\Exception $e)
						{
							Craft::log('Unable to determine if a script name redirect is in place on the server: '.$e->getMessage(), LogLevel::Error);
						}
					}

					// Cache it
					Craft::$app->getCache()->set('omitScriptNameInUrls', $this->_omitScriptNameInUrls);
				}
			}
		}

		return ($this->_omitScriptNameInUrls == 'y');
	}

	/**
	 * Returns whether generated URLs should be formatted using PATH_INFO, taking the
	 * [usePathInfo](http://buildwithcraft.com/docs/config-settings#usePathInfo) config setting value into account.
	 *
	 * This method will usually only be called in the event that [[omitScriptNameInUrls()]] returns `false`
	 * (so “index.php” _should_ be included), and it determines what follows “index.php” in the URL.
	 *
	 * If it returns `true`, a forward slash will be used, making “index.php” look like just another segment of the URL
	 * (e.g. http://example.com/index.php/some/path). Otherwise the Craft path will be included in the URL as a query
	 * string param named ‘p’ (e.g. http://example.com/index.php?p=some/path).
	 *
	 * If the usePathInfo config setting is set to `true` or `false`, then its value will be returned directly.
	 * Otherwise, `usePathInfo()` will try to determine whether the server is set up to support PATH_INFO.
	 * (See http://buildwithcraft.com/help/enable-path-info for instructions.)
	 *
	 * It does that by creating a dummy request to the site URL with the URI “/index.php/testPathInfo”. If the server
	 * supports it, that request should be sent to Craft’s index.php file, which will detect that this is an
	 * PATH_INFO-testing request, and simply return “success”. If anything besides “success” is returned
	 * (i.e. an Apache-styled 404 error), then Craft assumes the server is not set up to support PATH_INFO.
	 *
	 * Results of the PATH_INFO test request will be cached for the amount of time specified by the
	 * [cacheDuration](http://buildwithcraft.com/docs/config-settings#cacheDuration) config setting.
	 *
	 * @return bool Whether generaletd URLs should be formatted using PATH_INFO.
	 */
	public function usePathInfo()
	{
		if (!isset($this->_usePathInfo))
		{
			$this->_usePathInfo = 'n';

			// Check if the config value has actually been set to true/false
			$configVal = $this->get('usePathInfo');

			if (is_bool($configVal))
			{
				$this->_usePathInfo = ($configVal === true ? 'y' : 'n');
			}
			else
			{
				// Check if it's cached
				$cachedVal = Craft::$app->getCache()->get('usePathInfo');

				if ($cachedVal !== false)
				{
					$this->_usePathInfo = $cachedVal;
				}
				else
				{
					// If there is already a PATH_INFO var available, we know it supports it.
					// Added the !empty() check for nginx.
					if (!empty($_SERVER['PATH_INFO']))
					{
						$this->_usePathInfo = 'y';
					}
					// PHP Dev Server supports path info, and doesn't support simultaneous requests, so we need to
					// explicitly check for that.
					else if (AppHelper::isPhpDevServer())
					{
						$this->_usePathInfo = 'y';
					}
					else
					{
						// Cache it early so the testPathInfo request isn't checking for it too
						Craft::$app->getCache()->set('usePathInfo', 'n');

						// Test the server for it
						try
						{
							$url = Craft::$app->getRequest()->getHostInfo().Craft::$app->getRequest()->getScriptUrl().'/testPathInfo';
							$client = new \Guzzle\Http\Client();
							$response = $client->get($url, [], ['connect_timeout' => 2, 'timeout' => 4])->send();

							if ($response->isSuccessful() && $response->getBody(true) === 'success')
							{
								$this->_usePathInfo = 'y';
							}
						}
						catch (\Exception $e)
						{
							Craft::log('Unable to determine if PATH_INFO is enabled on the server: '.$e->getMessage(), LogLevel::Error);
						}
					}

					// Cache it
					Craft::$app->getCache()->set('usePathInfo', $this->_usePathInfo);
				}
			}
		}

		return ($this->_usePathInfo == 'y');
	}

	/**
	 * Sets PHP’s memory limit to the maximum specified by the
	 * [phpMaxMemoryLimit](http://buildwithcraft.com/docs/config-settings#phpMaxMemoryLimit) config setting, and gives
	 * the script an unlimited amount of time to execute.
	 *
	 * @return null
	 */
	public function maxPowerCaptain()
	{
		// I need more memory.
		@ini_set('memory_limit', Craft::$app->config->get('phpMaxMemoryLimit'));

		// I need more time.
		@set_time_limit(0);
	}

	/**
	 * Returns the configured user session duration in seconds, or `null` if there is none because user sessions should
	 * expire when the HTTP session expires.
	 *
	 * You can choose whether the
	 * [rememberedUserSessionDuration](http://buildwithcraft.com/docs/config-settings#rememberedUserSessionDuration)
	 * or [userSessionDuration](http://buildwithcraft.com/docs/config-settings#userSessionDuration) config setting
	 * should be used with the $remembered param. If rememberedUserSessionDuration’s value is empty (disabling the
	 * feature) then userSessionDuration will be used regardless of $remembered.
	 *
	 * @param bool $remembered Whether the rememberedUserSessionDuration config setting should be used if it’s set.
	 *                         Default is `false`.
	 *
	 * @return int|null The user session duration in seconds, or `null` if user sessions should expire along with the
	 *                  HTTP session.
	 */
	public function getUserSessionDuration($remembered = false)
	{
		if ($remembered)
		{
			$duration = Craft::$app->config->get('rememberedUserSessionDuration');
		}

		// Even if $remembered = true, it's possible that they've disabled long-term user sessions
		// by setting rememberedUserSessionDuration = 0
		if (empty($duration))
		{
			$duration = Craft::$app->config->get('userSessionDuration');
		}

		if ($duration)
		{
			return DateTimeHelper::timeFormatToSeconds($duration);
		}
	}

	/**
	 * Returns the user login path based on the type of the current request.
	 *
	 * If it’s a front-end request, the [loginPath](http://buildwithcraft.com/docs/config-settings#loginPath) config
	 * setting value will be returned. Otherwise the path specified in [[getCpLoginPath()]] will be returned.
	 *
	 * @return string The login path.
	 */
	public function getLoginPath()
	{
		$request = Craft::$app->getRequest();

		if ($request->getIsConsoleRequest() || $request->getIsSiteRequest())
		{
			return $this->getLocalized('loginPath');
		}

		return $this->getCpLoginPath();
	}

	/**
	 * Returns the user logout path based on the type of the current request.
	 *
	 * If it’s a front-end request, the [logoutPath](http://buildwithcraft.com/docs/config-settings#logoutPath) config
	 * setting value will be returned. Otherwise the path specified in [[getCpLogoutPath()]] will be returned.
	 *
	 * @return string The logout path.
	 */
	public function getLogoutPath()
	{
		$request = Craft::$app->getRequest();

		if ($request->getIsConsoleRequest() || $request->getIsSiteRequest())
		{
			return $this->getLocalized('logoutPath');
		}

		return $this->getCpLogoutPath();
	}

	/**
	 * Returns a user’s Activate Account path with a given activation code and user’s UID.
	 *
	 * @param string $code The activation code.
	 * @param string $uid  The user’s UID.
	 * @param bool   $full Whether a full URL should be returned. Defauls to `true`.
	 *
	 * @return string The Activate Account path.
	 *
	 * @internal This is a little awkward in that the method is called getActivateAccount**Path**, but by default it
	 * returns a full **URL**. And in the event that you do just want the path (as the name of the method implies),
	 * you have to pass in a $code and $uid just to be able to set the 4th argument to `false`, even though those
	 * variables will won't be used.
	 *
	 * @todo Create a new getActivateAccountUrl() method (probably elsewhere, such as UrlHelper) which handles
	 * everything that setting $full to `true` currently does here. The function should accept an actual UserModel
	 * rather than their UID, for consistency with [[getSetPasswordPath()]]. Let this function continue working as a
	 * wrapper for getActivateAccountUrl() for the time being, with deprecation logs.
	 */
	public function getActivateAccountPath($code, $uid, $full = true)
	{
		$url = $this->get('actionTrigger').'/users/setPassword';

		if (!$full)
		{
			return $url;
		}

		if (Craft::$app->getRequest()->getIsSecureConnection())
		{
			$url = UrlHelper::getUrl($url, [
				'code' => $code, 'id' => $uid
			], 'https');
		}

		$url = UrlHelper::getUrl($url, [
			'code' => $code, 'id' => $uid
		]);

		// Special case because we don't want the CP trigger showing in the email.
		return str_replace($this->get('cpTrigger').'/', '', $url);
	}

	/**
	 * Returns a user’s Set Password path with a given activation code and user’s UID.
	 *
	 * @param string    $code    The activation code.
	 * @param string    $uid     The user’s UID.
	 * @param UserModel $user The user.
	 * @param bool      $full Whether a full URL should be returned. Defaults to `false`.
	 *
	 * @return string The Set Password path.
	 *
	 * @internal This is a little awkward in that the method is called getActivateAccount**Path**, but it's also capable
	 * of returning a full **URL**. And it requires you pass in both a user’s UID *and* the UserModel - presumably we
	 * could get away with just the UserModel and get the UID from that.
	 *
	 * @todo Create a new getSetPasswordUrl() method (probably elsewhere, such as UrlHelper) which handles
	 * everything that setting $full to `true` currently does here. The function should not accetp a UID since that's
	 * already covered by the UserModel. Let this function continue working as a wrapper for getSetPasswordUrl() for the
	 * time being, with deprecation logs.
	 */
	public function getSetPasswordPath($code, $uid, $user, $full = false)
	{
		if ($user->can('accessCp'))
		{
			$url = $this->getCpSetPasswordPath();

			if ($full)
			{
				if (Craft::$app->getRequest()->getIsSecureConnection())
				{
					$url = UrlHelper::getCpUrl($url, [
						'code' => $code, 'id' => $uid
					], 'https');
				}
				else
				{
					$url = UrlHelper::getCpUrl($url, [
						'code' => $code, 'id' => $uid
					]);
				}
			}
		}
		else
		{
			$url = $this->getLocalized('setPasswordPath');

			if ($full)
			{
				if (Craft::$app->getRequest()->getIsSecureConnection())
				{
					$url = UrlHelper::getUrl($url, [
						'code' => $code, 'id' => $uid
					], 'https');
				}
				else
				{
					$url = UrlHelper::getUrl($url, [
						'code' => $code, 'id' => $uid
					]);
				}
			}
		}

		return $url;
	}

	/**
	 * Returns the path to the CP’s Set Password page.
	 *
	 * @return string The Set Password path.
	 */
	public function getCpSetPasswordPath()
	{
		return 'setpassword';
	}

	/**
	 * Returns the path to the CP’s Login page.
	 *
	 * @return string The Login path.
	 */
	public function getCpLoginPath()
	{
		return 'login';
	}

	/**
	 * Returns the path to the CP’s Logout page.
	 *
	 * @return string The Logout path.
	 */
	public function getCpLogoutPath()
	{
		return 'logout';
	}

	/**
	 * Parses a string for any [environment variables](http://buildwithcraft.com/docs/multi-environment-configs#environment-specific-variables).
	 *
	 * This method simply loops through all of the elements in the
	 * [environmentVariables](http://buildwithcraft.com/docs/config-settings#environmentVariables) config setting’s
	 * value, and replaces any {tag}s in the string that have matching keys with their corresponding values.
	 *
	 * @param string $str The string that should be parsed for environment variables.
	 *
	 * @return string The parsed string.
	 */
	public function parseEnvironmentString($str)
	{
		foreach ($this->get('environmentVariables') as $key => $value)
		{
			$str = str_replace('{'.$key.'}', $value, $str);
		}

		return $str;
	}

	/**
	 * Returns the Resource Request trigger word based on the type of the current request.
	 *
	 * If it’s a front-end request, the [resourceTrigger](http://buildwithcraft.com/docs/config-settings#resourceTrigger)
	 * config setting value will be returned. Otherwise `'resources'` will be returned.
	 *
	 * @return string The Resource Request trigger word.
	 */
	public function getResourceTrigger()
	{
		$request = Craft::$app->getRequest();

		if (!$request->getIsConsoleRequest() && $request->getIsCpRequest())
		{
			return 'resources';
		}
		else
		{
			return $this->get('resourceTrigger');
		}
	}

	// Private Methods
	// =========================================================================

	/**
	 * @param $name
	 */
	private function _loadConfigFile($name)
	{
		// Is this a valid Craft config category?
		if (ConfigCategory::isValidName($name))
		{
			$defaultsPath = CRAFT_APP_PATH.'config/defaults/'.$name.'.php';
		}
		else
		{
			$defaultsPath = CRAFT_PLUGINS_PATH.$name.'/config.php';
		}

		if (IOHelper::fileExists($defaultsPath))
		{
			$defaultsConfig = @require_once($defaultsPath);
		}

		if (!isset($defaultsConfig) || !is_array($defaultsConfig))
		{
			$defaultsConfig = [];
		}

		// Little extra logic for the general config category.
		if ($name == ConfigCategory::General)
		{
			// Does craft/config/general.php exist? (It used to be called blocks.php so maybe not.)
			if (file_exists(CRAFT_CONFIG_PATH.'general.php'))
			{
				if (is_array($customConfig = @include(CRAFT_CONFIG_PATH.'general.php')))
				{
					$this->_mergeConfigs($defaultsConfig, $customConfig);
				}
			}
			else if (file_exists(CRAFT_CONFIG_PATH.'blocks.php'))
			{
				// Originally blocks.php defined a $blocksConfig variable, and then later returned an array directly.
				if (is_array($customConfig = require_once(CRAFT_CONFIG_PATH.'blocks.php')))
				{
					$this->_mergeConfigs($defaultsConfig, $customConfig);
				}
				else if (isset($blocksConfig))
				{
					$defaultsConfig = array_merge($defaultsConfig, $blocksConfig);
					unset($blocksConfig);
				}
			}
		}
		else
		{
			$customConfigPath = CRAFT_CONFIG_PATH.$name.'.php';
			if (IOHelper::fileExists($customConfigPath))
			{
				if (is_array($customConfig = @include($customConfigPath)))
				{
					$this->_mergeConfigs($defaultsConfig, $customConfig);
				}
				else if ($name == ConfigCategory::Db)
				{
					// Originally db.php defined a $dbConfig variable.
					if (@require_once(CRAFT_CONFIG_PATH.'db.php'))
					{
						$this->_mergeConfigs($defaultsConfig, $dbConfig);
						unset($dbConfig);
					}
				}
			}
		}

		$this->_loadedConfigFiles[$name] = $defaultsConfig;
	}

	/**
	 * Merges a base config array with a custom config array, taking environment-specific configs into account.
	 *
	 * @param array &$baseConfig
	 * @param array $customConfig
	 *
	 * @return null
	 */
	private function _mergeConfigs(&$baseConfig, $customConfig)
	{
		// Is this a multi-environment config?
		if (array_key_exists('*', $customConfig))
		{
			$mergedCustomConfig = [];

			foreach ($customConfig as $env => $envConfig)
			{
				if ($env == '*' || StringHelper::contains(CRAFT_ENVIRONMENT, $env))
				{
					$mergedCustomConfig = ArrayHelper::merge($mergedCustomConfig, $envConfig);
				}
			}

			$customConfig = $mergedCustomConfig;
		}

		$baseConfig = array_merge($baseConfig, $customConfig);
	}
}
