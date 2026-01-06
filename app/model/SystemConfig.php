<?php
namespace app\model;

use think\Model;

class SystemConfig extends Model
{
    protected $table = 'system_config';
    
    /**
     * 获取配置值（自动解码JSON）
     */
    public static function get($name, $default = null)
    {
        $config = self::where('name', $name)->find();
        if (!$config) {
            return $default;
        }
        
        $value = $config->value;
        $json = json_decode($value, true);
        
        return json_last_error() === JSON_ERROR_NONE ? $json : $value;
    }
    
    /**
     * 设置配置值（自动编码JSON）
     * 改名为 setValue 以避免与 Model::set 冲突
     */
    public static function setValue($name, $value, $group = 'system')
    {
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        
        $config = self::where('name', $name)->find();
        if ($config) {
            $config->value = $value;
            $config->update_time = time();
            $config->save();
        } else {
            self::create([
                'name' => $name,
                'value' => $value,
                'group' => $group,
                'update_time' => time()
            ]);
        }
        
        return true;
    }
}