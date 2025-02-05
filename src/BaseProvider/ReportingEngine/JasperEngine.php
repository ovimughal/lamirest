<?php

use Lamirest\DI\ServiceInjector;

require(ServiceInjector::oFileManager()->getConfigValue('java_bridge'));

function initSetup($language, $properties)
{
    if (!array_key_exists('outputFileName', $properties)) {
        $properties['outputFileName'] = ServiceInjector::oFileManager()->getConfigValue('output_file_name');
    }

    if (!array_key_exists('templateMargins', $properties)) {
        $properties['templateMargins'] = [
            'top' => 0,
            'right' => 0,
            'bottom' => 0,
            'left' => 0
        ];
    }

    // set language
    $GLOBALS['language'] = $language;
    // set filname that will be generated
    $orgId = ServiceInjector::oFileManager()->organizationId();
    $output_unique_key = time();
    $GLOBALS['outputFileName'] = $orgId . '_' . $output_unique_key . '_' . $properties['outputFileName'];
    // set template directory to be used where jrxml reports are available
    $GLOBALS['templateDir'] = getcwd() . '/' . ServiceInjector::oFileManager()->getConfigValue('reporting_templates_' . $language);
    // set the directory where output file with the name set above is generated
    $GLOBALS['outputDir'] = getcwd() . '/' . ServiceInjector::oFileManager()->getConfigValue('reporting_output');
    // set properties
    $GLOBALS['templateMargins'] = $properties['templateMargins'];
}

function executeJasper(
    $sqlQuery,
    $reportTemplate,
    $parameters = [],
    $subReportParameters = [],
    $outputFormat = 'pdf',
    $language = 'en',
    $properties = [],
    $generateNew = false,
    $printCopies = ['Original']
) {
    try {
        // initialize
        initSetup($language, $properties);

        // Connect to Database
        $conn = dataBaseConnection();

        // Set Parameters if any
        $params = setParameters($parameters, $subReportParameters);

        // Load Report Template & Compile Report with Query
        $report = loadAndCompileReport($reportTemplate, $sqlQuery);

        // Instantiatle Java Fill Manager
        $fillManager = new JavaClass('net.sf.jasperreports.engine.JasperFillManager');

        // Fill with compiled Report, Params & database connection object
        // Normal single print usage
        // $jasperPrint = $fillManager->fillReport($report, $params, $conn);

        // List usage to print multiple copies
        $jasperPrintList = new Java('java.util.ArrayList');
        foreach ($printCopies as $value){
            // Will be printed on report head whether duplicate or triplicate
            if (count($printCopies) === 1) {
                $value = '';
            }
            $params->put('reportType', $value);
             // Fill with compiled Report, Params & database connection object
            $jasperPrint = $fillManager->fillReport($report, $params, $conn);
            $jasperPrintList->add($jasperPrint);
        }

        // Export report to pdf, html, csv etc or generate to png, jpg etc
        if ($generateNew) {
            $GLOBALS['outputDir'] = getcwd() . '/' . ServiceInjector::oFileManager()->getConfigValue('reporting_templates_img_output');
            $result = generateNewOutput($jasperPrint, $outputFormat);
        } else {
            // Usage without List
            // $result = exportOutput($jasperPrint, $outputFormat);
            $result = exportOutput($jasperPrintList, $outputFormat);
        }

        //$url = $_SERVER['HTTP_REFERER'];
        //echo '<script>window.open(''.$url.'doc/' . $output . '.'.$format.'');</script>';
        //chmod($outputPath, 0777);
        //readfile($outputPath);
        //chmod($outputPath, $mode)
        //unlink($outputPath);
    } catch (Exception $exc) {
        throw new Exception('Execute Exception: ' . $exc);
    } finally {        
        $conn->close();
    }
    return $result;
}

function dataBaseConnection()
{
    try {

        $dbms = ServiceInjector::oFileManager()->getConfigValue('dbms');
        $dbmsServer = ServiceInjector::oFileManager()->getConfigValue('dbms_server');
        $dataBaseName = ServiceInjector::oFileManager()->getConfigValue('data_base_name');
        $dataBaseUser = ServiceInjector::oFileManager()->getConfigValue('data_base_user');
        $dataBasePassword = ServiceInjector::oFileManager()->getConfigValue('data_base_password');

        // Instantiate Java Lang class to set Database driver
        $class = new JavaClass('java.lang.Class');

        // Instantiate Java DriverManager for Database
        $driverManager = new JavaClass('java.sql.DriverManager');

        // switch to Database
        switch ($dbms) {
            case 'sqlsrv':
                // MS SQL Server Driver
                $class->forName('com.microsoft.sqlserver.jdbc.SQLServerDriver');
                // MS SQL Server Connection
                $conn = $driverManager->getConnection('jdbc:sqlserver://' . $dbmsServer . ';databaseName=' . $dataBaseName, $dataBaseUser, $dataBasePassword);
                break;
            case 'mysql':
                // MySql Driver
                $class->forName('com.mysql.jdbc.Driver');
                // MySQl Server Connection
                $conn = $driverManager->getConnection('jdbc:mysql://' . $dbmsServer . '/' . $dataBaseName . '?zeroDateTimeBehavior=convertToNull', $dataBaseUser, $dataBasePassword);
                break;
            case 'pgsql':
                // MySql Driver
                $class->forName('org.postgresql.Driver');
                // MySQl Server Connection
                $conn = $driverManager->getConnection('jdbc:postgresql://' . $dbmsServer . '/' . $dataBaseName . '?zeroDateTimeBehavior=convertToNull', $dataBaseUser, $dataBasePassword);
                break;
        }
    } catch (JavaException $exc) {
        throw new Exception('Database Connection Exception: ' . $exc);
    }
    return $conn;
}

function loadAndCompileReport($reportTemplate, $sqlQuery)
{
    try {

        // Load Report Template Directory
        $templateDir = $GLOBALS['templateDir'];

        // Instantiate Jasper XML Loader
        $jasperxml = new java('net.sf.jasperreports.engine.xml.JRXmlLoader');

        // Load Jasper Report .jrxml Template
        $jasperDesign = $jasperxml->load($templateDir . '/' . $reportTemplate);

        // Instantiate Jasper Query 
        $query = new java('net.sf.jasperreports.engine.design.JRDesignQuery');

        // Set Sql Query to be executed
        $query->setText($sqlQuery);
        $jasperDesign->setQuery($query);

        // set template margins if any
        setTemplateMargins($jasperDesign);

        // Instantiate Jasper Compiler
        $compileManager = new JavaClass('net.sf.jasperreports.engine.JasperCompileManager');

        // Compile Report
        $report = $compileManager->compileReport($jasperDesign);
    } catch (JavaException $exc) {
        throw new Exception('Load And Compile Exception: ' . $exc);
    }

    return $report;
}

function setParameters($parameters = [], $subReportParameters = [])
{
    try {

        // Instantiate Java HashMap
        $params = new Java('java.util.HashMap');

        // Set Parameters
        //$params->put('title', 'Customer Profile');

        if (is_array($parameters)) {
            if (count($parameters)) {
                foreach ($parameters as $key => $value) {
                    $params->put($key, $value);
                }
            }
        }

        // for subreports
        if (is_array($subReportParameters)) {
            if (count($subReportParameters)) {
                $templateDir = $GLOBALS['templateDir'];
                foreach ($subReportParameters as $key => $value) {
                    $params->put($key, $templateDir . '/' . $value);
                }
            }
        }
    } catch (JavaException $exc) {
        throw new Exception('Parameters Exception: ' . $exc);
    }

    return $params;
}

function setTemplateMargins($jasperDesign)
{
    $templateMargins = $GLOBALS['templateMargins'];
    try {
        // set margins properties
        ($templateMargins['left'] > 0) ?  $jasperDesign->setLeftMargin(intval($templateMargins['left'])) : '';
        ($templateMargins['right'] > 0) ?  $jasperDesign->setRightMargin(intval($templateMargins['right'])) : '';
        ($templateMargins['top'] > 0) ?  $jasperDesign->setTopMargin(intval($templateMargins['top'])) : '';
        ($templateMargins['bottom'] > 0) ?  $jasperDesign->setBottomMargin(intval($templateMargins['bottom'])) : '';
    } catch (JavaException $exc) {
        throw new Exception('Parameters Exception: ' . $exc);
    }
}

function exportOutput($jasperPrint, $outputFormat = 'pdf')
{
    $outputFileName = $GLOBALS['outputFileName']; // use global variable
    $outputDir = $GLOBALS['outputDir']; // get output directory
    try {

        // Instantiate Jasper Exporter
        $exporter = new java('net.sf.jasperreports.engine.JRExporter');

        // Switch which format to output
        // default format is pdf
        switch ($outputFormat) {
            case 'xls':
                $outputPath = $outputDir . '/' . $outputFileName . '.xls';

                $exporter = new java('net.sf.jasperreports.engine.export.JRXlsExporter');
                $exporter->setParameter(java('net.sf.jasperreports.engine.export.JRXlsExporterParameter')->IS_ONE_PAGE_PER_SHEET, java('java.lang.Boolean')->FALSE);
                $exporter->setParameter(java('net.sf.jasperreports.engine.export.JRXlsExporterParameter')->IS_WHITE_PAGE_BACKGROUND, java('java.lang.Boolean')->TRUE);
                $exporter->setParameter(java('net.sf.jasperreports.engine.export.JRXlsExporterParameter')->IS_REMOVE_EMPTY_SPACE_BETWEEN_ROWS, java('java.lang.Boolean')->TRUE);
                $exporter->setParameter(java('net.sf.jasperreports.engine.export.JRXlsExporterParameter')->IS_REMOVE_EMPTY_SPACE_BETWEEN_COLUMNS, java('java.lang.Boolean')->TRUE);
                $exporter->setParameter(java('net.sf.jasperreports.engine.export.JRXlsExporterParameter')->IS_IGNORE_GRAPHICS, java('java.lang.Boolean')->TRUE);
                $exporter->setParameter(java('net.sf.jasperreports.engine.export.JRXlsExporterParameter')->IS_IMAGE_BORDER_FIX_ENABLED, java('java.lang.Boolean')->TRUE);
                $exporter->setParameter(java('net.sf.jasperreports.engine.export.JRXlsExporterParameter')->IS_DETECT_CELL_TYPE, java('java.lang.Boolean')->TRUE);




                // $exporter->setParameter(java('net.sf.jasperreports.engine.JRExporterParameter')->JASPER_PRINT, $jasperPrint);
                $exporter->setParameter(java('net.sf.jasperreports.engine.JRExporterParameter')->JASPER_PRINT_LIST, $jasperPrint);
                $exporter->setParameter(java('net.sf.jasperreports.engine.JRExporterParameter')->OUTPUT_FILE_NAME, $outputPath);

                //header('Content-type: application/vnd.ms-excel');
                //header('Content-Disposition: attachment; filename=' .$outputFileName. '.xls');
                break;
            case 'csv':
                $outputPath = $outputDir . '/' . $outputFileName . '.csv';

                $exporter = new java('net.sf.jasperreports.engine.export.JRCsvExporter');
                $exporter->setParameter(java('net.sf.jasperreports.engine.export.JRCsvExporterParameter')->FIELD_DELIMITER, ',');
                $exporter->setParameter(java('net.sf.jasperreports.engine.export.JRCsvExporterParameter')->RECORD_DELIMITER, '\n');
                $exporter->setParameter(java('net.sf.jasperreports.engine.export.JRCsvExporterParameter')->CHARACTER_ENCODING, 'UTF-8');
                // $exporter->setParameter(java('net.sf.jasperreports.engine.JRExporterParameter')->JASPER_PRINT, $jasperPrint);
                $exporter->setParameter(java('net.sf.jasperreports.engine.JRExporterParameter')->JASPER_PRINT_LIST, $jasperPrint);
                $exporter->setParameter(java('net.sf.jasperreports.engine.JRExporterParameter')->OUTPUT_FILE_NAME, $outputPath);

                //header('Content-type: application/csv');
                //header('Content-Disposition: attachment; filename=' .$outputFileName. '.csv');
                break;
            case 'docx':
                $outputPath = $outputDir . '/' . $outputFileName . '.docx';

                $exporter = new java('net.sf.jasperreports.engine.export.ooxml.JRDocxExporter');
                // $exporter->setParameter(java('net.sf.jasperreports.engine.JRExporterParameter')->JASPER_PRINT, $jasperPrint);
                $exporter->setParameter(java('net.sf.jasperreports.engine.JRExporterParameter')->JASPER_PRINT_LIST, $jasperPrint);
                $exporter->setParameter(java('net.sf.jasperreports.engine.JRExporterParameter')->OUTPUT_FILE_NAME, $outputPath);

                //header('Content-type: application/vnd.ms-word');
                //header('Content-Disposition: attachment; filename=' .$outputFileName. '.docx');
                break;
            case 'html':
                $outputPath = $outputDir . '/' . $outputFileName . '.html';

                $exporter = new java('net.sf.jasperreports.engine.export.HtmlExporter');
                // $exporter->setParameter(java('net.sf.jasperreports.engine.JRExporterParameter')->JASPER_PRINT, $jasperPrint);
                $exporter->setParameter(java('net.sf.jasperreports.engine.JRExporterParameter')->JASPER_PRINT_LIST, $jasperPrint);
                $exporter->setParameter(java('net.sf.jasperreports.engine.JRExporterParameter')->OUTPUT_FILE_NAME, $outputPath);

                break;
            case 'pdf':
                $outputPath = $outputDir . '/' . $outputFileName . '.pdf';

                $exporter = new java('net.sf.jasperreports.engine.export.JRPdfExporter');
                // $exporter->setParameter(java('net.sf.jasperreports.engine.JRExporterParameter')->JASPER_PRINT, $jasperPrint);
                $exporter->setParameter(java('net.sf.jasperreports.engine.JRExporterParameter')->JASPER_PRINT_LIST, $jasperPrint);
                $exporter->setParameter(java('net.sf.jasperreports.engine.JRExporterParameter')->OUTPUT_FILE_NAME, $outputPath);

                //        header('Content-type: application/pdf');
                //        header('Content-Disposition: inline; filename=' .$outputFileName. '.pdf');
                break;
            case 'ods':
                $outputPath = $outputDir . '/' . $outputFileName . '.ods';

                $exporter = new java('net.sf.jasperreports.engine.export.oasis.JROdsExporter');
                // $exporter->setParameter(java('net.sf.jasperreports.engine.JRExporterParameter')->JASPER_PRINT, $jasperPrint);
                $exporter->setParameter(java('net.sf.jasperreports.engine.JRExporterParameter')->JASPER_PRINT_LIST, $jasperPrint);
                $exporter->setParameter(java('net.sf.jasperreports.engine.JRExporterParameter')->OUTPUT_FILE_NAME, $outputPath);

                //        header('Content-type: application/vnd.oasis.opendocument.spreadsheet');
                //        header('Content-Disposition: attachment; filename=' .$outputFileName. '.ods');
                break;
            case 'odt':
                $outputPath = $outputDir . '/' . $outputFileName . '.odt';

                $exporter = new java('net.sf.jasperreports.engine.export.oasis.JROdtExporter');
                // $exporter->setParameter(java('net.sf.jasperreports.engine.JRExporterParameter')->JASPER_PRINT, $jasperPrint);
                $exporter->setParameter(java('net.sf.jasperreports.engine.JRExporterParameter')->JASPER_PRINT_LIST, $jasperPrint);
                $exporter->setParameter(java('net.sf.jasperreports.engine.JRExporterParameter')->OUTPUT_FILE_NAME, $outputPath);

                //        header('Content-type: application/vnd.oasis.opendocument.text');
                //        header('Content-Disposition: attachment; filename=' .$outputFileName. '.odt');
                break;
            case 'txt':
                $outputPath = $outputDir . '/' . $outputFileName . '.txt';

                $exporter = new java('net.sf.jasperreports.engine.export.JRTextExporter');
                $exporter->setParameter(java('net.sf.jasperreports.engine.export.JRTextExporterParameter')->PAGE_WIDTH, 120);
                $exporter->setParameter(java('net.sf.jasperreports.engine.export.JRTextExporterParameter')->PAGE_HEIGHT, 60);
                // $exporter->setParameter(java('net.sf.jasperreports.engine.JRExporterParameter')->JASPER_PRINT, $jasperPrint);
                $exporter->setParameter(java('net.sf.jasperreports.engine.JRExporterParameter')->JASPER_PRINT_LIST, $jasperPrint);
                $exporter->setParameter(java('net.sf.jasperreports.engine.JRExporterParameter')->OUTPUT_FILE_NAME, $outputPath);

                //        header('Content-type: text/plain');
                break;
            case 'rtf':
                $outputPath = $outputDir . '/' . $outputFileName . '.rtf';

                $exporter = new java('net.sf.jasperreports.engine.export.JRRtfExporter');
                // $exporter->setParameter(java('net.sf.jasperreports.engine.JRExporterParameter')->JASPER_PRINT, $jasperPrint);
                $exporter->setParameter(java('net.sf.jasperreports.engine.JRExporterParameter')->JASPER_PRINT_LIST, $jasperPrint);
                $exporter->setParameter(java('net.sf.jasperreports.engine.JRExporterParameter')->OUTPUT_FILE_NAME, $outputPath);

                //        header('Content-type: application/rtf');
                //        header('Content-Disposition: attachment; filename=' .$outputFileName. '.rtf');
                break;
            case 'pptx':
                $outputPath = $outputDir . '/' . $outputFileName . '.pptx';

                $exporter = new java('net.sf.jasperreports.engine.export.ooxml.JRPptxExporter');
                // $exporter->setParameter(java('net.sf.jasperreports.engine.JRExporterParameter')->JASPER_PRINT, $jasperPrint);
                $exporter->setParameter(java('net.sf.jasperreports.engine.JRExporterParameter')->JASPER_PRINT_LIST, $jasperPrint);
                $exporter->setParameter(java('net.sf.jasperreports.engine.JRExporterParameter')->OUTPUT_FILE_NAME, $outputPath);

                //        header('Content-type: aapplication/vnd.ms-powerpoint');
                //        header('Content-Disposition: attachment; filename=' .$outputFileName. '.pptx');
                break;
        }
        //generate report
        $exporter->exportReport();

        // Change file permissions so that it is readable
        $file = new Java('java.io.File', $outputPath);
        // if second arg is set to true or ignored it mean owner only
        // false means for everyone
        // $file->setExecutable(true, false);
        $file->setReadable(true, false);
        $file->setWritable(true, false);

        // Clean up generated reports older than 2 days
        $cmd = "find $outputDir -type f -name '*_report.pdf' -mmin +15 -delete";
        $cmdRslt = exec($cmd, $output, $tsltCode);

        // outputFileName is the name that is used when downloading the file
        // $ouputFileName = ServiceInjector::oFileManager()->getConfigValue('output_file_name');       
        $routeResource = 'reporting_file_download_route';
        $result = ServiceInjector::oFileManager()->getFileDownloadLink($routeResource, $outputFileName, $outputFormat); //'Report Generated Successfully';
    } catch (JavaException $exc) {
        throw new Exception('Export Output Exception: ' . $exc);
    }
    return $result;
}

function generateNewOutput($jasperPrint, $outputFormat = 'png')
{
    $outputFileName = $GLOBALS['outputFileName']; // get output filename
    $outputDir = $GLOBALS['outputDir']; // get output directory
    try {
        $fileName = $outputFileName . '.' . $outputFormat;
        $outputPath = $outputDir . '/' . $fileName;

        $file = new Java('java.io.File', $outputPath);
//        $file->setExecutable(true, false); /*(executeable,owneronly)**/
//        $file->setReadable(true, false);
//        $file->setWritable(true, false);

        $fileOutputStream = new Java('java.io.FileOutputStream', $file);
        $printContext = (new Java('net.sf.jasperreports.engine.DefaultJasperReportsContext'))->getInstance();
        $printManager = (new Java('net.sf.jasperreports.engine.JasperPrintManager'))->getInstance($printContext);
        $renderedImage = $printManager->printPageToImage($jasperPrint, 0, 1);
        // $renderedImage = $printManager->print($jasperPrint, true);
        $imageIo =  new Java('javax.imageio.ImageIO');
        $imageIo->write($renderedImage, $outputFormat, $fileOutputStream);

        $result = $fileName;
    } catch (JavaException $exc) {
        throw new Exception('Generate Output Exception: ' . $exc);
    }
    return $result;
}
