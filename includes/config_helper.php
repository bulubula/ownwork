<?php

/**
 * 配置助手函数
 * 提供读取和写入JSON配置文件的功能
 */

/**
 * 读取配置项
 * @param string $key 配置项键名
 * @param mixed $default 默认值
 * @return mixed 配置值或默认值
 */
function get_config($key, $default = null)
{
    static $config = null;
    
    // 延迟加载配置文件
    if ($config === null) {
        $configFile = __DIR__ . '/config.json';
        if (file_exists($configFile)) {
            $jsonContent = file_get_contents($configFile);
            if ($jsonContent !== false) {
                $config = json_decode($jsonContent, true);
                if (is_null($config)) {
                    $config = [];
                }
            } else {
                $config = [];
            }
        } else {
            // 配置文件不存在，初始化空数组并创建默认配置
            $config = [
                'allocation_enabled' => true
            ];
            
            // 创建默认配置文件
            $jsonContent = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            file_put_contents($configFile, $jsonContent);
        }
    }
    
    // 获取配置值
    if (isset($config[$key])) {
        return $config[$key];
    }
    
    return $default;
}

/**
 * 修改配置项
 * @param string $key 配置项键名
 * @param mixed $value 配置值
 * @return bool 是否修改成功
 */
function set_config($key, $value)
{
    $configFile = __DIR__ . '/config.json';
    
    // 读取当前配置
    $config = [];
    if (file_exists($configFile)) {
        $jsonContent = file_get_contents($configFile);
        if ($jsonContent !== false) {
            $config = json_decode($jsonContent, true);
            if (is_null($config)) {
                $config = [];
            }
        }
    }
    
    // 更新配置
    $config[$key] = $value;
    
    // 保存配置文件
    $jsonContent = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
    return file_put_contents($configFile, $jsonContent) !== false;
}