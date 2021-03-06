<?php

/**
 * This file is part of the {@link http://ontowiki.net OntoWiki} project.
 *
 * @copyright Copyright (c) 2008, {@link http://aksw.org AKSW}
 * @license http://opensource.org/licenses/gpl-license.php GNU General Public License (GPL)
 */

/**
 * Component controller for the CSV Importer.
 *
 * @category OntoWiki
 * @package Extensions
 * @subpackage Csvimport
 * @copyright Copyright (c) 2010, {@link http://aksw.org AKSW}
 * @license http://opensource.org/licenses/gpl-license.php GNU General Public License (GPL)
 */
class CsvimportController extends OntoWiki_Controller_Component
{
    public function init()
    {
        // init component
        parent::init();

        $this->view->headScript()->appendFile($this->_componentUrlBase . 'scripts/csvimport.js');
        $this->view->headScript()->appendFile($this->_componentUrlBase . 'scripts/rdfa.object.js');

        // remove navigation tab
        OntoWiki::getInstance()->getNavigation()->disableNavigation();

        // authenticate as Admin
        // TODO: grant permissions to edit everything to anonym
        $erfurt = $this->_owApp->erfurt;
        $username   = 'Admin';
        $password   = '';
        $authResult = $erfurt->authenticate($username, $password);
    }

    public function indexAction()
    {
        $this->_forward('upload');
    }


    public function processuriAction()
    {
        require_once('RemoteFile.php');
        //$test_uri = "http://data.london.gov.uk/datafiles/demographics/census-historic-population-borough.csv";
        //http://csvimport.vhost.tld/index.php/csvimport/?m=http%3A%2F%2Fcsvimport.vhost.tld%2Findex.php%2Fcsvimport_test%2F&ckanResourceUri=http://data.london.gov.uk/datafiles/demographics/census-historic-population-borough.csv
        $ckanResourceId = $this->_request->getParam ('resource_id', '');
        $remoteFile = new RemoteFile($ckanResourceId);
        $ckanResourceUri = $remoteFile->uri;
        $tempFile = $remoteFile->download();
        $importMode = 'scovo';

        //create random model
        $model = $this->_createRandomModel($ckanResourceId);
        $this->_owApp->selectedModel = $model;

        if (is_readable($tempFile)) {
            $store = $this->_getSessionStore();
            $store->importedFile = $tempFile;
            $store->importMode   = $importMode;
            $store->resourceUrl  = $ckanResourceUri;

            if($store->importMode == 'tabular') {
                $store->csvSeparator = ",";
                $store->headlineDetection = true;
                if(empty($post['defaultSeparator'])) {
                    $store->csvSeparator = str_replace("\\\\", '\\', $post['separator']);
                }
                if(empty($post['headlineDetection'])) {
                    $store->headlineDetection = false;
                }
            }
        }

        // now we map
        $this->_forward('mapping');
    }

    public function uploadAction()
    {
        if (!isset($this->_request->upload)) {
            // clean store
            $this->_destroySessionStore();

            // TODO: show import dialogue and import file
            $this->view->placeholder('main.window.title')->append('Import CSV Data');
            //OntoWiki_Navigation::disableNavigation();

            $this->view->formActionUrl = $this->_config->urlBase . 'csvimport';
            $this->view->formEncoding  = 'multipart/form-data';
            $this->view->formClass     = 'simple-input input-justify-left';
            $this->view->formMethod    = 'post';
            $this->view->formName      = 'import';
            $this->view->referer       = isset($_SERVER['HTTP_REFERER']) ? urlencode($_SERVER['HTTP_REFERER']) : '';

            $this->view->modelUri   = (string)$this->_owApp->selectedModel;
            $this->view->title      = 'Import CSV Data';
            $model = $this->_owApp->selectedModel;
            if($model != null){
                $this->view->modelTitle = $model->getTitle();

                if ($model->isEditable()) {
                    $toolbar = $this->_owApp->toolbar;
                    $toolbar->appendButton(OntoWiki_Toolbar::SUBMIT, array('name' => 'Import CSV', 'id' => 'import'))
                            ->appendButton(OntoWiki_Toolbar::RESET, array('name' => 'Cancel'));
                    $this->view->placeholder('main.window.toolbar')->set($toolbar);
                } else {
                    $this->_owApp->appendMessage(
                        new OntoWiki_Message('No write permissions on model \''.$this->view->modelTitle.'\'', OntoWiki_Message::WARNING)
                    );
                }

                // FIX: http://www.webmasterworld.com/macintosh_webmaster/3300569.htm
                // disable connection keep-alive
                $response = $this->getResponse();
                $response->setHeader('Connection', 'close', true);
                $response->sendHeaders();
            return;
            } else {
                $this->_owApp->appendMessage(
                        new OntoWiki_Message('You need to select a model first', OntoWiki_Message::WARNING)
                    );
            }
        } else {
            // evaluate post data
            $messages = array();
            $post = $this->_request->getPost();
            $errorFlag = false;
            switch (true) {
                case (empty($_FILES['source']['name'])):
                    $message = 'No file selected. Please try again.';
                        $this->_owApp->appendMessage(
                            new OntoWiki_Message($message, OntoWiki_Message::ERROR)
                        );
                        $errorFlag = true;
                        break;
                case ($_FILES['source']['error'] == UPLOAD_ERR_INI_SIZE):
                    $message = 'The uploaded files\'s size exceeds the upload_max_filesize directive in php.ini.';
                        $this->_owApp->appendMessage(
                            new OntoWiki_Message($message, OntoWiki_Message::ERROR)
                        );
                        $errorFlag = true;
                        break;
                case ($_FILES['source']['error'] == UPLOAD_ERR_PARTIAL):
                    $this->_owApp->appendMessage(
                        new OntoWiki_Message('The uploaded file was only partially uploaded.', OntoWiki_Message::ERROR)
                    );
                    $errorFlag = true;
                    break;
                case ($_FILES['source']['error'] >= UPLOAD_ERR_NO_FILE):
                    $message = 'There was an unknown error during file upload. Please check your PHP configuration.';
                    $this->_owApp->appendMessage(
                        new OntoWiki_Message($message, OntoWiki_Message::ERROR)
                    );
                    $errorFlag = true;
                    break;
            }

            /* handle upload */
            $tempFile = $_FILES['source']['tmp_name'];
            if (is_readable($tempFile)) {
                $store = $this->_getSessionStore();
                $store->importedFile = $tempFile;
                $store->importMode   = $post['importMode'];
                $store->resourceUrl  = 'local';

                if($store->importMode == 'tabular') {
                    $store->csvSeparator = ",";
                    $store->headlineDetection = true;
                    if(empty($post['defaultSeparator'])) {
                        $store->csvSeparator = str_replace("\\\\", '\\', $post['separator']);
                    }
                    if(empty($post['headlineDetection'])) {
                        $store->headlineDetection = false;
                    }
                }
                // $store->nextAction   = 'mapping';
            }

            // now we map
            $this->_forward('mapping');
        }
    }

    public function mappingAction(){
        $this->view->staticUrlBase = $this->_config->staticUrlBase;
        $store = $this->_getSessionStore();
        if (!empty($store->importMode)) {
            $configuration = null;
            switch ($store->importMode) {
                case "tabular" :
                    require_once('TabularImporter.php');
                    $importer = new TabularImporter($this->view,
                                                    $this->_privateConfig,
                                                    array('headlineDetection' => $store->headlineDetection,
                                                          'separator' => $store->csvSeparator)
                                                   );
                    break;
                case "scovo" :
                    require_once('DataCubeImporter.php');
                    $importer = new DataCubeImporter($this->view, $this->_privateConfig);
                    break;
                default:
                    break;
            }

            $importer->setFile($store->importedFile);

            if (!empty($this->_request->dimensions)) {
                $json = $this->_request->dimensions;
                $json = str_replace('\\"', '"', $json);
                $configuration = json_decode($json, true);
            }

            if ($configuration) {
                $importer->setConfiguration($configuration);
                $importer->setParsedFile($store->parsedFile);
                $store->results = $importer->importData();
                $this->_helper->viewRenderer->setNoRender();
            } else {
                //get stored Configurations
                $importer->setStoredConfigurations($this->getStoredConfigurationUris());
                $importer->createConfigurationView($this->_config->urlBase);
                $store->parsedFile = $importer->getParsedFile();
            }
        }
    }

    protected function resultsAction()
    {
        $this->view->placeholder('main.window.title')->append('Import CSV Results');
        OntoWiki_Navigation::disableNavigation();
    }

    protected function importlogAction()
    {
        $this->_helper->viewRenderer->setNoRender();
        $this->_helper->layout->disableLayout();
        
        $config = $this->_owApp->getConfig();
        $path = $config->log->path;
        $contents = "";
        $filename = $path.'importer.log';
        $fp = fopen($filename, 'r');
        $contents = fread($fp, filesize($filename));
        fclose($fp);        
        echo $contents;
    }


    protected function getStoredConfigurationUris() {
        $dir = $this->_getConfigurationDir();
        if(!is_dir($dir)) return;

        $configurations = array();

        if ($dh = opendir($dir)) {
            while (($file = readdir($dh)) !== false) {
                if($file == "." || $file == '..') continue;

                $handle = fopen($dir.$file, 'r');
                $contents = fread($handle, filesize($dir.$file));
                fclose($handle);

                $configurations[] = array (
                                            'label' => str_replace('.cfg', '', str_replace('_', ' ', $file)),
                                            'config' => $contents );
            }
            closedir($dh);

            return $configurations;
        }
        return array();

        return;
        $sysontUri  = $this->_owApp->erfurt->getConfig()->sysont->modelUri;
        $sysOnt     = $this->_owApp->erfurt->getStore()->getModel($sysontUri, false);

        $query = new Erfurt_Sparql_SimpleQuery();
        $query->setProloguePart(' SELECT  ?configUri ?configLabel ?configuration') ;
        $query->setWherePart('
                    WHERE { ?configUri a <' . $sysontUri . 'CSVImportConfig> .
                            ?configUri <http://www.w3.org/2000/01/rdf-schema#label> ?configLabel .
                            ?configUri <' . $sysontUri . 'CSVImportConfig/configuration> ?configuration} ');

        if ($result = $sysOnt->sparqlQuery($query)) {
            // var_dump($result); die;
            $configurations = array();
            foreach ($result as $entry) {
                //var_dump($entry['configuration']); die;
                $configurations[$entry['configUri']] = array (
                                                        'label' => $entry['configLabel'],
                                                        'config' => base64_decode($entry['configuration']) );
            }
            return $configurations;
        }
        return array();
    }

    protected function saveconfigAction(){
        $this->_helper->viewRenderer->setNoRender();
        $this->_helper->layout->disableLayout();

        //$this->_createBaseModel() - by default requires Admin privileges
        //TODO: give the privileges to anonym
        if( $this->_createBaseModel() ){
            // get post params
            $post = $this->_request->getPost();

            $val = $post['configString'];
            $name = str_replace(" ", "_", $post['configName']);

            $dir = $this->_getConfigurationDir();
            if(!$this->_checkCreateDir($dir)) {
                echo "something was wrong while creating log at : " . $dir;
            }

            $fp = fopen($dir.$name.'.cfg', 'w');
            fwrite($fp, $val);
            fclose($fp);
        } 
        
    }

    protected function processfileAction(){
        $this->_helper->viewRenderer->setNoRender();
        $this->_helper->layout->disableLayout();
        $ckanResourceId = $this->_request->getParam ('resource_id', '');

        $this->_store = Erfurt_App::getInstance()->getStore();
        $this->_storeAdapter = Erfurt_App::getInstance(false)->getStore()->getBackendAdapter();

        $graphUri = 'http://csv2rdf.aksw.org/' . $ckanResourceId;
        $type = 'n3'; //csv2rdf provide n3 data
        $locator = Erfurt_Syntax_RdfParser::LOCATOR_FILE;

        $data = "/tmp/test.n3";

        $this->_storeAdapter->importRdf($graphUri, $data, $type, $locator);
        $defaultGraphUri=$graphUri;
        $serviceUri='http;//datacube.aksw.org/sparql';
        header('Location: http://datacube.aksw.org/facete-server/?default-graph-uri='.$defaultGraphUri.'&service-uri='.$serviceUri);

    }

    protected function _getConfigurationDir() {
        $dir = $this->_owApp->extensionManager->getExtensionPath('csvimport').'/configs/';
        $store = $this->_getsessionstore();
        if($store->resourceUrl == 'local') {
            $dir = $dir . 'local/';
        } else {
            $md5 = md5($store->resourceUrl);
            $dir = $dir . $md5 . '/';
        }
        return $dir;
    }

    protected function _checkCreateDir($dir) {
        if(!is_dir($dir)) {
            if(!mkdir($dir, 0777, true)){
                return False;
            }
        }
        return True;
    }

    protected function _createBaseModel(){
        // check access controll for SysOnt
        $sysontUri = $this->_owApp->erfurt->getConfig()->sysont->modelUri;
        $sysOnt = $this->_owApp->erfurt->getStore()->getModel($sysontUri, false);
        $allow = $this->_owApp->erfurt->getAc()->isModelAllowed('edit', $sysOnt);
        if ($allow) {
            // create config class
            // {sysont_ns}:CSVImportConfig
            // {sysont_ns}:CSVImportConfig rdfs:label "Configuration Class"

            $s = $sysontUri.'CSVImportConfig';
            $type = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type';
            $label = 'http://www.w3.org/2000/01/rdf-schema#label';
            $element[$s] = array(
                $type => array(
                    array(
                        'type' => 'uri',
                        'value' => 'http://www.w3.org/2002/07/owl#Class'
                    )
                ),
                $label => array(
                    array(
                        'type' => 'literal',
                        'value' => 'CSV Import Configuration'
                    )
                )
            );

            $sysOnt->addMultipleStatements( $element );

            // create config string property
            // {sysont_ns}:CSVImportConfig/configuration
            // {sysont_ns}:CSVImportConfig/configuration rdfs:label "Configuration"
            // {sysont_ns}:CSVImportConfig/configuration rdfs:domain {sysont_ns}:CSVImportConfig

            $element = array();
            $sp = $s.'/configuration';
            $label = 'http://www.w3.org/2000/01/rdf-schema#label';
            $domain = 'http://www.w3.org/2000/01/rdf-schema#domain';
            $element[$sp] = array(
                $domain => array(
                    array(
                        'type' => 'uri',
                        'value' => $s
                    )
                ),
                $label => array(
                    array(
                        'type' => 'literal',
                        'value' => 'configuration'
                    )
                )
            );

            $sysOnt->addMultipleStatements( $element );

            return true;
        }else{
            return false;
        }
    }

    protected function _getSessionStore()
    {
        $session = new Zend_Session_Namespace('CSV_IMPORT_SESSION');
        return $session;
    }

    protected function _destroySessionStore(){
        Zend_Session::namespaceUnset('CSV_IMPORT_SESSION');
    }

    protected function _createRandomModel($ckanResourceId) {
        //generate model name
        //$modelName = 'http://csv2rdf.aksw.org/' . md5($ckanResourceUri) . '/' . time();
        $modelName = 'http://csv2rdf.aksw.org/' . $ckanResourceId;

        $this->_store = Erfurt_App::getInstance()->getStore();
        $this->_storeAdapter = Erfurt_App::getInstance(false)->getStore()->getBackendAdapter();
        $this->_storeAdapter->deleteModel($modelName);
        $this->_storeAdapter->createModel($modelName);
        $model = $this->_store->getModel($modelName);

        $this->_ac           = Erfurt_App::getInstance(false)->getAc();
        $this->_ac->setUserModelRight($modelName, 'view', 'grant');
        $this->_ac->setUserModelRight($modelName, 'edit', 'grant');

        return $model;
    }
}

/*
 *
 * SELECT  ?configUri ?configLabel ?configuration
WHERE { ?configUri a <http://localhost/OntoWiki/Config/CSVImportConfig> .
?configUri <http://www.w3.org/2000/01/rdf-schema#label> ?configLabel .
?configUri <http://localhost/OntoWiki/Config/CSVImportConfig/configuration> ?configuration .
      }
 *
 */
