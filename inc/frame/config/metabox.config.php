<?php
/*
 * @Theme Name:WebStack
 * @Theme URI:https://www.iotheme.cn/
 * @Author: iowen
 * @Author URI: https://www.iowen.cn/
 * @Date: 2021-08-22 19:00:30
 * @LastEditors: iowen
 * @LastEditTime: 2024-07-30 18:14:07
 * @FilePath: /WebStack/inc/frame/config/metabox.config.php
 * @Description: 
 */
if ( ! defined( 'ABSPATH' ) ) { die; } // Cannot access pages directly.

$options[] = array(
    'id' => 'sites_meta',
    'title' => '网址链接属性',
    'post_type' => 'sites',
    'data_type' => 'unserialize',
    'context' => 'normal',
    'priority' => 'high',
    'sections'  => array(
        array(
            'name'   => 'section_4',
            'fields' => array(
                array(
                    'id' => '_visible',
                    'type' => 'radio',
                    'title' => '可查看用户',
                    'class'   => 'horizontal',
                    'options' => array(
                        '1' => '仅管理员可见',
                        '2' => '登陆可见',
                        '0' => '所有人',
                    ),
                    'default' => '0',
                ),
                array(
                    "id" => "_sites_link",
                    "type"=>"text",
                    "title" => "输入网址链接，",
                    'after' =>'需包含 http(s)://<br><span style="font-weight: normal;color: crimson;margin-top: 10px;display: block;">注意：“网址”和“公众号二维码”两者可同时填写，但是至少填一项。</span>',
                ),
            
                array(
                    "id" => "_sites_sescribe",
                    "type"=>"text",
                    "title" => "描叙",
                ),
            
                array(
                    "id" => "_sites_order",
                    "std" => "0",
                    "title" => "网址排序数值越大越靠前",
                    "type"=>"text"
                ),
            
                array(
                    "id" => "_thumbnail",
                    "type"=>"image",
                    "title" => "添加图标地址，调用自定义图标",
                    'add_title' => '添加图标',
                    /*
                     * 说明写在 info 里：框架会渲染成块级 <p class="cs-text-desc">，
                     * 与输入框左对齐、灰色小字。不要写进 after —— after 是行内
                     * 直接拼接，文字会紧贴「添加图标」按钮右边开始流，左边缘参差。
                     * <p> 内只能用行内元素，故用 <br> 换行，不用 div/table。
                     */
                    'info' => '支持三种填法（同一个框，可混用）：<br>'
                        . '① 本地图片 —— 点上方「添加图标」，从媒体库选择后自动填入地址<br>'
                        . '② 图片链接 —— 粘贴 http(s):// 开头的地址<br>'
                        . '③ Iconify 图标 —— 填图标名，如 <code>mdi:home</code>、'
                        . '<code>simple-icons:github</code>、<code>logos:google-gmail</code>，'
                        . '图标名可在 <a href="https://icon-sets.iconify.design/" target="_blank" rel="noopener">icon-sets.iconify.design</a> 搜索<br>'
                        . 'Iconify 图标由 api.iconify.design 实时生成，不可达时回退到默认图标。',
                ),
            
                array(
                    "id" => "_wechat_qr",
                    "type"=>"image",
                    "title" => "添加公众号二维码",
                    'add_title' => '添加二维码',
                ),
            ),
        ),

    ),
);
CSFramework_Metabox::instance( $options );