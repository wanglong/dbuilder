<?php
/**
 * Created by PhpStorm.
 * User: lvyahui
 * Date: 2016/5/7
 * Time: 15:28
 */

namespace admin;

define('MODULE_ROUTES', json_encode(include(app_path() . '/module_routes.php')));

use Illuminate\Support\Facades\Redirect;
use SiteHelpers;
use BaseModel;
use Module;
use ConfigUtils;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Response;

class ModuleController extends AdminController
{
    protected function beforeEdit(&$data)
    {
        $data ['dataSources'] = SiteHelpers::loadDataSources();
        if ($data['model']->id) {
            $data ['moduleConf'] = ConfigUtils::get($data['model']->name);
        }
    }

    protected function afterSave($module)
    {
        /* 生成代码文件 */
        $codes = array(
            'moduleName'      => $module->name,
            'moduleTitle'     => $module->title,
            'tablePrimaryKey' => BaseModel::findPrimarykey($module->db_table, $module->db_source),
            'moduleNote'      => $module->note,
            'date'            => date('Y-m-d'),
            'dbSource'        => $module->db_source,
            'dbTable'         => $module->db_table,
        );
        $this->removeFiles($codes['moduleName']);
        /* 生成默认module Configuration*/
        $moduleConfs = $this->buildConfiguration($module->db_table, $module->db_source);
        SiteHelpers::saveArrayToFile(app_path('config/crud/') . snake_case($codes['moduleName']) . '.php', $moduleConfs);

        $controller = file_get_contents(app_path('template') . '/controller.tpl');
        $model = file_get_contents(app_path('template') . '/model.tpl');
        $formView = file_get_contents(app_path('template') . '/_form.tpl');
        $listView = file_get_contents(app_path('template') . '/_list.tpl');
        $codes['timestamps'] = isset($moduleConfs['fields']['created_at']) && isset($moduleConfs['fields']['updated_at']);
        $buildController = SiteHelpers::blend($controller, $codes);
        $buildModel = SiteHelpers::blend($model, $codes);
        /* 生成 MVC 文件*/
        file_put_contents(app_path() . "/controllers/admin/{$codes['moduleName']}Controller.php", $buildController);
        file_put_contents(app_path() . "/models/{$codes['moduleName']}.php", $buildModel);
        $viewPath = app_path('/views/admin/') . snake_case($codes['moduleName']);
        if (!file_exists($viewPath)) mkdir($viewPath);
        file_put_contents($viewPath . "/_form.blade.php", $formView);
        file_put_contents($viewPath . "/_list.blade.php", $listView);


        /* 更新路由 */
        $moduleRoutes = json_decode(MODULE_ROUTES, true); //require(app_path().'/module_routes.php');
        if (is_array($moduleRoutes)) {
            $moduleRoutes[SiteHelpers::reducCase($codes['moduleName'])] = 'admin\\' . "{$codes['moduleName']}Controller";
            SiteHelpers::saveArrayToFile(app_path() . '/module_routes.php', $moduleRoutes);
        }
    }

    protected function beforeDelete($id)
    {
        $module = Module::find($id);
        $moduleName = $module->name;
        $this->removeFiles($moduleName);
    }

    public function removeFiles($moduleName)
    {
        $controller = app_path('admin/controllers') . "/{$moduleName}Controller.php";
        if (file_exists($controller)) {
            unlink($controller);
        }
        $model = app_path('models') . "/{$moduleName}.php";
        if (file_exists($model)) {
            unlink($model);
        }
        $moduleConf = app_path('config/crud/') . snake_case($moduleName) . '.php';
        if (file_exists($moduleConf)) {
            unlink($moduleConf);
        }

        $viewPath = app_path('/views/admin/') . snake_case($moduleName);
        $formFile = $viewPath . '/_form.blade.php';
        $listFile = $viewPath . '/_list.blade.php';
        if (file_exists($formFile)) unlink($formFile);
        if (file_exists($listFile)) unlink($listFile);
    }

    private function buildConfiguration($table, $connection)
    {
        $rawColumns = BaseModel::getTableColumns($table, $connection);
        $fields = ConfigUtils::build($rawColumns);
        return array(
            'data_source' => $connection,
            'table'       => $table,
            'fields'      => $fields,
        );
    }

    public function getFieldsConfig()
    {
        $filedsConfig = null;
        if (Input::has('module_name')) {
            $filedsConfig = ConfigUtils::get(Input::get('module_name'))['fields'];
        } else {
            $table = Input::get('table');
            $connection = Input::get('connection');

            $filedsConfig = $this->buildConfiguration($table, $connection)['fields'];
        }

        $resp = $this->makeView(array(
            'fieldsConfig' => $filedsConfig,
        ));
        if ($resp) {
            return $resp;
        }
    }


    public function postSaveFieldsConf()
    {
        $resp = Redirect::action(get_class($this) . '@getEdit', Input::get('id'));
        $postFields = Input::get('fields');
        $moduleName = Input::get('module_key');
        $confKey = SiteHelpers::reducCase($moduleName);
        $savedConfig = ConfigUtils::get($confKey);
        foreach ($savedConfig['fields'] as $fieldName => &$savefield) {
            $postField = $postFields[$fieldName];
            $savefield['label'] = $postField['label'];
            $savefield['form']['show'] = isset($postField['form']['show']);
            $savefield['list']['show'] = isset($postField['list']['show']);
        }
        ConfigUtils::saveGModuleConf($confKey, $savedConfig);
        return $resp;
    }

    public function getFieldConfig()
    {
        $moduleKey = Input::get('module_key');
        $field = Input::get('field');

        $moduleConfig = Config::get('crud/' . snake_case($moduleKey));
        $fieldConf = &$moduleConfig['fields'][$field];
        $dbSource = SiteHelpers::loadDataSources()[$moduleConfig['data_source']];
        $tables = BaseModel::getTableList($dbSource['database'],$moduleConfig['data_source']);

        $resp = $this->makeView(array(
            'field'       => $field,
            'fieldConfig' => $fieldConf,
            'moduleKey'   => $moduleKey,
            'tables'    =>  $tables,
            'connection'    =>  $moduleConfig['data_source'],
        ));

        if ($resp) return $resp;
    }

    public function postFieldConfig()
    {
        $data = array(
            'success' => true,
        );
        $moduleKey = Input::get('module_key');
        $field = Input::get('field');
        $moduleConfig = Config::get('crud/' . snake_case($moduleKey));
        $fieldConf = &$moduleConfig['fields'][$field];

        $postFormConf = Input::get('form');
        $postListConf = Input::get('list');
        $postRelationConf = Input::get('relation');
        $fieldConf['form']['type'] = $postFormConf['type'];
        if($postFormConf['type'] === 'select'
            && isset($postFormConf['options'])
            && $postFormConf['options']
        ){
            $rawOptions = explode(',',$postFormConf['options']);
            $options = array();
            foreach($rawOptions as $option){
                $options[$option]   = $option;
            }
            $fieldConf['form']['options'] = $options;
        }
        $fieldConf['form']['placeholder'] = $postFormConf['placeholder'];
        $fieldConf['list']['sort'] = isset($postListConf['sort']);
        $fieldConf['list']['search'] = $postListConf['search'];

        $fieldConf['relation']  =   $postRelationConf;

        SiteHelpers::saveArrayToFile(app_path('config/crud/' . snake_case($moduleKey) . '.php'), $moduleConfig);

        return Response::json($data);
    }
}