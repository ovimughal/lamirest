<?php
/*
 * This file is part of Lamirest.
 *
 * (c) OwaisMughal <ovi.mughal@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Lamirest\Services;

use Exception;
use Lamirest\BaseProvider\OhandlerBaseProvider;
use Lamirest\DI\ServiceInjector;
use Lamirest\Sniffers\OexceptionSniffer;
use Laminas\Http\Headers;
use Laminas\Http\Response;
use Laminas\Http\Response\Stream;
use Laminas\View\Model\JsonModel;

/**
 * Description of OfilemanagerService
 *
 * @author OviMughal
 */
class OfilemanagerService extends OhandlerBaseProvider
{    
    public function __construct()
    {
        parent::__construct();
        define('SLASH', (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') ? '\\' : '/');
    }
    
    public function getAppRootPath()
    {
        return getcwd();
    }

//    public function getAppBasePath()
//    {
//        $basePath = $this->getOconfigManager()['settings']['base_path'];
//        $fullPath = getcwd().'/'.$basePath;
//        
//        return $fullPath;
//    }

    public function getFolderPath($resourceName = null)
    {
        if (null != $resourceName && isset($this->getOconfigManager()['settings'][$resourceName])) {
            $path = $this->getOconfigManager()['settings'][$resourceName];
            if(SLASH === '\\'){
                $path = str_replace('/', '\\', $path);
            }
            
            $fullPath = getcwd() . SLASH . $path;

            if (!is_dir($fullPath)) {
                $fullPath = 'Invalid Directory';
            }
        } else {
            $fullPath = 'Wrong/No Resource';
        }
        return $fullPath;
    }

    public function getConfigValue($key, $domain = 'settings')
    {
        if (!empty($key) && isset($this->getOconfigManager()[$domain][$key])) {
            $value = $this->getOconfigManager()[$domain][$key];
        } else {
            $value = 'Wrong/No Key';
        }

        return $value;
    }

    public function downloadFile($filename, $resourceName = null)
    {
        $response = new Stream();
        if (!empty($filename)) {
            $path = $this->getFolderPath($resourceName) . SLASH;

            if (!is_readable($path . $filename)) {
                // Set 404 Not Found status code
                $response->setStatusCode(404);
            } else {

                //$response = new \Laminas\Http\Response\Stream();
                $response->setStream(fopen($path . $filename, "r"));

                $response->setStatusCode(200);

                $mainType = 'application';
                $type = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

                $imageTypes = ['png', 'jpg', 'jpeg', 'gif'];

                if (in_array($type, $imageTypes)) {
                    $mainType = 'image';
                }

                $headers = new Headers();
                $headers->addHeaderLine('Content-Type', $mainType . '/' . $type)
                        ->addHeaderLine('Content-Disposition', 'inline; filename="' . basename($filename) . '"')
                        ->addHeaderLine('Content-Length', filesize($path . $filename))
                        ->addHeaderLine("Cache-control: private");
                $response->setHeaders($headers);
            }
        } else {
            $response->setStatusCode(404);
        }

        if ($response->getStatusCode() != 200) {
            $this->setData(OexceptionSniffer::exceptionScanner(new Exception('Either filename is empty or not readable, Please verify filename')));
            $response = new JsonModel($this->getResult());
        }
        return $response;
    }

    public function getFileData($fileName, $fileResource = null, $fromFileServer = false)
    {
        $response = new Response();
        if (!empty($fileName) && null != $fileResource) {

            if (!$fromFileServer) {
                $file = $this->getFolderPath($fileResource) . SLASH . $fileName;
                $rawData = file_get_contents($file);
                $contentType = mime_content_type($file);
            } else {
                $fileServer = $this->getOconfigManager()['settings']['file_server'];
                $filePath = $this->getOconfigManager()['settings'][$fileResource];

                $url = $fileServer . $filePath . $fileName;

                $ch = curl_init();

                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_HEADER, false);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.11 (KHTML, like Gecko) Chrome/23.0.1271.1 Safari/537.11');

                $rawData = curl_exec($ch);

                //$rescode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

                curl_close($ch);
            }

            if ('text/html;charset=UTF-8' !== $contentType) {
                $fileData = $this->getCompiledFileData($rawData, $contentType);
            } else {
                $fileData = false;
            }
        } else {
            $response->setStatusCode(404);
        }

        if ($response->getStatusCode() != 200) {
            $this->setData(OexceptionSniffer::exceptionScanner(new Exception('Either filename is empty or not readable, Please verify filename')));
            $response = new JsonModel($this->getResult());
        } else {
            $response = $fileData;
        }

        return $response;
    }

    public function getCompiledFileData($rawData, $contentType)
    {
        $encodedFileData = base64_encode($rawData);
        // $type = pathinfo($fileName, PATHINFO_EXTENSION);
        // $fileData = 'data:image/' . $type . ';base64,' . $encodedFileData;
        $fileData = 'data:' . $contentType . ';base64,' . $encodedFileData;

        return $fileData;
    }

    public function getSecureHyperlinkKey()
    {
        $encodedSecureKey = ServiceInjector::oEncryption()->hyperlinkEncodedKey();
        // $keySeperatorSalt = $this->getConfigValue('hyperlink_security_salt', 'api');
        // $apiKeySecurityOne = $this->getConfigValue('hyperlink_api_key_security_one', 'api');
        // $apiKeySecurityTwo = $this->getConfigValue('hyperlink_api_key_security_two', 'api');
        // $apiKey = $this->getConfigValue('api_key', 'api');
        // $jwt = ServiceInjector::oJwtizer()->getOjwt();

        // $secureKey = $apiKeySecurityOne . $apiKey . $apiKeySecurityTwo . $keySeperatorSalt . $jwt;

        // $encodedSecureKey = base64_encode($secureKey);

        $secureKey = '';

        if(ServiceInjector::oTenant()->isMultitenancyEnabled()){
            $orgQueryString = $this->organizationId(true);
            $secureKey .= $orgQueryString.'&';
        }

        $secureKey .= 'key=' . $encodedSecureKey;

        return $secureKey;
    }

    public function getFileDownloadLink($routeResource, $filename, $format = null, $getAbsoluteFilePath = false)
    {
        $getAbsoluteFilePath = (($GLOBALS['ABSOLUTE_FILE_PATH_FOR_LOGOS'] ?? false) || ($GLOBALS['ABSOLUTE_FILE_PATH_FOR_EMAIL_POPUP'] ?? false)) ? true : $getAbsoluteFilePath;
        $downloadLink = $this->getConfigValue($routeResource);
        $outputFormat = null == $format ? '' : '.' . $format;

        if ($getAbsoluteFilePath) {
            $route = explode('/',$downloadLink);
            $resourceName = end($route);
            // This is the actual physical file path which will be used only internally
            // e.g when sending a file as attachment or show logo on reports. Why to do circular requests
            $fileDownloadLink = $this->getFolderPath($resourceName) . SLASH . $filename . $outputFormat;
        } else {
            if ($downloadLink !== 'Wrong/No Key') {
                // To encode filename having special chars & spaces in them
                $filename = rawurlencode($filename);
                $secureKey = $this->getSecureHyperlinkKey();                
                $linkId = md5(time());
    
                $fileDownloadLink = $downloadLink . '/' . $filename . $outputFormat . '?' . $secureKey.'&linkId='.$linkId;
            } else {
                $fileDownloadLink = 'Invalid configuration key';
            }
        }

        return $fileDownloadLink;
    }

    // This method is not used
    // It is a backup or logic behind mime_content_type
    public function getMimeType($param)
    {
        if (!function_exists('mime_content_type')) {

            function mime_content_type($filename)
            {

                $mime_types = array(
                    'txt' => 'text/plain',
                    'htm' => 'text/html',
                    'html' => 'text/html',
                    'php' => 'text/html',
                    'css' => 'text/css',
                    'js' => 'application/javascript',
                    'json' => 'application/json',
                    'xml' => 'application/xml',
                    'swf' => 'application/x-shockwave-flash',
                    'flv' => 'video/x-flv',
                    // images
                    'png' => 'image/png',
                    'jpe' => 'image/jpeg',
                    'jpeg' => 'image/jpeg',
                    'jpg' => 'image/jpeg',
                    'gif' => 'image/gif',
                    'bmp' => 'image/bmp',
                    'ico' => 'image/vnd.microsoft.icon',
                    'tiff' => 'image/tiff',
                    'tif' => 'image/tiff',
                    'svg' => 'image/svg+xml',
                    'svgz' => 'image/svg+xml',
                    // archives
                    'zip' => 'application/zip',
                    'rar' => 'application/x-rar-compressed',
                    'exe' => 'application/x-msdownload',
                    'msi' => 'application/x-msdownload',
                    'cab' => 'application/vnd.ms-cab-compressed',
                    // audio/video
                    'mp3' => 'audio/mpeg',
                    'qt' => 'video/quicktime',
                    'mov' => 'video/quicktime',
                    // adobe
                    'pdf' => 'application/pdf',
                    'psd' => 'image/vnd.adobe.photoshop',
                    'ai' => 'application/postscript',
                    'eps' => 'application/postscript',
                    'ps' => 'application/postscript',
                    // ms office
                    'doc' => 'application/msword',
                    'rtf' => 'application/rtf',
                    'xls' => 'application/vnd.ms-excel',
                    'ppt' => 'application/vnd.ms-powerpoint',
                    // open office
                    'odt' => 'application/vnd.oasis.opendocument.text',
                    'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
                );

                $ext = strtolower(array_pop(explode('.', $filename)));
                if (array_key_exists($ext, $mime_types)) {
                    $mime = $mime_types[$ext];
                } elseif (function_exists('finfo_open')) {
                    $finfo = finfo_open(FILEINFO_MIME);
                    $mimetype = finfo_file($finfo, $filename);
                    finfo_close($finfo);
                    $mime = $mimetype;
                } else {
                    $mime = 'application/octet-stream';
                }

                return $mime;
            }

        }
    }

}
