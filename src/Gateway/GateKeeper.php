<?php

/*
 * This file is part of Lamirest.
 *
 * (c) OwaisMughal <ovi.mughal@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Lamirest\Gateway;

use Laminas\Http\Response;
use Lamirest\DI\ServiceInjector;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Lamirest\Services\OaclService;
use Lamirest\Services\OapisecurityService;
use Lamirest\Services\OConfigHighjackerService;
use Lamirest\Services\OjwtizerService;
use Lamirest\Services\OlanguageService;

/**
 * Description of GateKeeper
 *
 * @author OviMughal
 */
class GateKeeper extends AbstractPlugin
{

    public function routeIdentifier(\Laminas\Mvc\MvcEvent $e): Response
    {
        $this->injectServiceLocator($e);
        //        $this->appLanguage($e);

        $oConfigMngr = ServiceInjector::$serviceLocator->get('config')['oconfig_manager'];
        $loginEnabled = $oConfigMngr['settings']['enable_login'];
        $enableAcl = $oConfigMngr['settings']['enable_acl'];
        $appDevEnv = $oConfigMngr['settings']['app_development_env'];
        $multitenancyEnabled = $oConfigMngr['settings']['enable_multitenancy'];
        $openIdentityRoutes = $oConfigMngr['open_identity_routes'];
        $openAccessRoutes = $oConfigMngr['open_access_routes'];
        $ssoRoutes = $oConfigMngr['sso_routes'];
        $openPublicRoutes = $oConfigMngr['open_public_routes'];
        define('ENV', is_bool($appDevEnv) ? $appDevEnv : true);

        $fullRoute = $e->getRouteMatch()->getMatchedRouteName();
        $routeArr = explode('/', $fullRoute);
        $route = ($routeArr && count($routeArr)) ? $routeArr[0] : null;

        /**
         * @var Response
         */
        $res = $e->getResponse();

        if ($route && !in_array($route, $openPublicRoutes)) {
            $res = $this->isApiKeyValid($e);
        }

        if ($res->getStatusCode() == 200 && !in_array($route, $openPublicRoutes)) {

            if ($route && !in_array($route, $openIdentityRoutes)) {
                // if ('login' != $routeArr[0]) {

                $isSSO = in_array($route, $ssoRoutes);
                if ($loginEnabled) {
                    $res = $this->identify($isSSO);
                    // /**
                    //  * @var Request
                    //  */
                    // $req = ServiceInjector::$serviceLocator->get('Request');
                    // $isSSO = in_array($route, $ssoRoutes); //ServiceInjector::oJwtizer()->getSsoProvider();
                }
                if ($res->getStatusCode() == 200 && !$isSSO) {
                    if ($multitenancyEnabled) {
                        $res = $this->tenantScanner();
                    }
                    if ($res->getStatusCode() == 200 && !in_array($route, $openAccessRoutes) && $enableAcl) {
                        $res = $this->accessVerifier($e);
                    }
                }
            }
            // $route = 'login/post' == $e->getRouteMatch()->getMatchedRouteName() ? 'login' : $e->getRouteMatch()->getMatchedRouteName();
            //            if ('login' != $route ||
            //                    (('login' == $route) &&
            //                    ('POST' != $e->getRequest()->getMethod()))
            //            ) {
            //                if($loginEnabled){
            //                $res = $this->identify($e);
            //                }
            //                if ($res->getStatusCode() == 200) {
            //                    $res = $this->accessVerifier($e);
            //                }
            //            }
        }

        return $res;
    }

    private function identify($isSSO): Response
    {
        $ojwtManager = ServiceInjector::oJwtizer();
        $ojwtManager->setIsSso($isSSO);
        $ojwtManager->init();
        return $ojwtManager->ojwtValidator();
    }

    private function tenantScanner(): Response
    {
        $tenant = ServiceInjector::oTenant();
        return $tenant->tenantIdentifier();
        // $configHighjacker = ServiceInjector::oConfigHighjacker();
        // // $configHighjacker = new OConfigHighjacker(ServiceInjector::$serviceLocator);
        // return $configHighjacker->overrideDbConfig();
    }

    private function injectServiceLocator(\Laminas\Mvc\MvcEvent $e): void
    {
        ServiceInjector::$serviceLocator = $e->getApplication()->getServiceManager();
    }

    private function isApiKeyValid(\Laminas\Mvc\MvcEvent $e): Response
    {
        $sm = $e->getApplication()->getServiceManager();
        /**
         * @var OapisecurityService
         */
        $oapiSecurityManager = $sm->get('Oapisecurity');
        return $oapiSecurityManager->apiKeyScanner();
    }

    private function accessVerifier(\Laminas\Mvc\MvcEvent $e): Response
    {
        $sm = $e->getApplication()->getServiceManager();
        /**
         * @var OaclService
         */
        $oaclManager = $sm->get('Oacl');
        return $oaclManager->authorizationCheck($e);
    }

    // public function appLanguage(\Laminas\Mvc\MvcEvent $e): void
    // {
    //     $sm = $e->getApplication()->getServiceManager();
    //     /**
    //      * @var OlanguageService
    //      */
    //     $oLanguage = $sm->get('Olanguage');
    //     $oLanguage::extractLanguage($sm);
    // }
}
