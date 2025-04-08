# Hook-Installer

[![Latest Stable Version](https://poser.pugx.org/captainhook/hook-installer/v/stable.svg?v=1)](https://packagist.org/packages/captainhook/hook-installer)
[![Minimum PHP Version](https://img.shields.io/badge/php-%3E%3D%208.0-8892BF.svg)](https://php.net/)
[![Downloads](https://img.shields.io/packagist/dt/captainhook/hook-installer.svg?v1)](https://packagist.org/packages/captainhook/hook-installer)
[![License](https://poser.pugx.org/captainhook/hook-installer/license.svg?v=1)](https://packagist.org/packages/captainhook/hook-installer)

*HookInstaller* is a *Composer* plugin for [CaptainHook](https://github.com/captainhook-git/captainhook) it takes
care of activating your local git hooks after `composer install` or `composer update`.
If you want to make sure your teammates activate their hooks, install this plugin
and you don't have to remind them anymore.

For more information about `CaptainHook` visit the [Website](http://captainhook.info/).

## Installation:

As this is a *Composer* plugin you should use *Composer* to install it.

```bash
$ composer require --dev captainhook/hook-installer
```
For this to work you must have `CaptainHook` installed already.
If you need help installing `CaptainHook` have a look at the Captain´s [README](https://github.com/captainhook-git/captainhook)

Everything else should happen automagically.

## Customize

If you choose to not put your configuration in the default location you can set the path to your `CaptainHook` configuration file.
If you installed `CaptainHook` without using any of its Composer packages `captainhook/captainhook`
or `captainhook/captainhook-phar` you have to set the path to the executable.
All extra config settings are optional and if you are using the default settings you do not have to 
configure anything to make it work.
 
```json
{
  "extra": {
    "captainhook": {
      "config": "config/captainhook.json",
      "exec": "tools/captainhook.phar",
      "force-install": true,
      "only-enabled": true,
      "disable-plugin": false
    }    
  }  
}

```

## A word of warning

It is still possible to commit without invoking the hooks. 
So make sure you run appropriate backend-sanity checks on your code!
