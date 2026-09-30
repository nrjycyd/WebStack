<?php 
/*
 * @Theme Name:WebStack
 * @Theme URI:https://github.com/owen0o0/WebStack
 * @Author: iowen
 * @Author URI: https://www.iowen.cn/
 * @Date: 2019-02-22 21:26:02
 * @LastEditors: iowen
 * @LastEditTime: 2023-04-24 00:42:32
 * @FilePath: \WebStack\footer.php
 * @Description: 
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
$_icp = '';
if(io_get_option('icp')){
    $_icp .= '<a href="https://beian.miit.gov.cn/" target="_blank" rel="link noopener">' . io_get_option('icp') . '</a>&nbsp;';
}
if ($police_icp = io_get_option('police_icp')) {
    if (preg_match('/\d+/', $police_icp, $arr)) {
        $_icp .= ' <a href="http://www.beian.gov.cn/portal/registerSystemInfo?recordcode=' . $arr[0] . '" target="_blank" class="'.$class.'" rel="noopener">' . $police_icp . '</a>&nbsp;';
    }
}
?>
            <footer class="main-footer sticky footer-type-1">
                <div class="go-up">
                    <a href="#" rel="go-top">
                        <i class="fa fa-angle-up"></i>
                    </a>
                </div>
                <div class="footer-inner">
                    <!---请保留版权说明，谢谢---->
                    <div class="footer-text">
                        Copyright © <?php echo date('Y') ?> <?php bloginfo('name'); ?> <?php echo $_icp ?>
                        &nbsp;&nbsp;Design by <a href="https://github.com/WebStackPage/WebStackPage.github.io" target="_blank"><strong>Webstack</strong></a>&nbsp;&nbsp;Modify by <a href="https://github.com/owen0o0/WebStack" target="_blank"><strong>iowen</strong></a>
                    </div>
                    <!---请保留版权说明，谢谢---->
                </div>
            </footer>
        </div>
    </div>
<!-- ===== 页脚贴底修正 =====
     【问题】页面内容不足一屏时（窗口较高、或按 Ctrl+- 缩小页面），
             Copyright 栏下方会出现一大片空白。

     【原因】主题 css/nav.css 里：
               .page-container{display:table;height:100%}      <- 容器被写死成整屏高
               footer.main-footer{background-color:#fff}       <- 页脚是白色块
               body{background-color:#f9f9f9}                  <- 页面底色是浅灰
             而页脚只是普通块级元素，所以内容短时它停在内容末尾，
             容器剩下的部分露出灰底 —— 看上去就是页脚下面"空了一块"。
             窄屏（<=767px）更明显：.main-content 变成 position:absolute、height:auto，
             它不再撑满视口，页脚自然贴不到底。

     【做法】只在「页脚下方确实还有空白」时，才把页脚钉到 .main-content 底部。
             .main-content 本身就是 position:relative（桌面端又是会被拉伸到整屏高的
             table-cell），所以 bottom:0 就等于视口底部。
             因为只在有多余空间时才钉住，所以**永远不会与上方内容重叠**
             —— 这是纯 CSS 方案做不到的安全保证。

     放在 footer.php 的原因：这是页脚自身的问题，且 get_footer() 被全部模板调用，
     所有页面都能修到；同时脚本位于页脚之后，DOM 已就绪。
     ===== -->
<script>
(function () {
    'use strict';

    var CLASS = 'is-footer-bottom';

    var style = document.createElement('style');
    style.textContent =
        /* 选择器带上 .page-container 提高权重，压过主题在窄屏下的 position:relative */
        '.page-container .main-content.' + CLASS + ' > .main-footer{' +
        'position:absolute;bottom:0;left:0;right:0;margin-left:0;margin-right:0;}' +
        /* 窄屏时 .main-content 是绝对定位且 height:auto，给它最小高度它才会撑到视口底部；
           50px 是移动端导航栏占位（主题里 .main-content 的 top:50px）。
           用 % 而不是 vh，避免移动端浏览器地址栏导致的 100vh 偏差。 */
        '@media (max-width:767px){' +
        '.page-container > .main-content{min-height:calc(100% - 50px);}}';
    document.head.appendChild(style);

    var content = null;
    var footer = null;
    var timer = null;

    function find() {
        content = document.querySelector('.main-content');
        footer = null;
        if (!content) { return false; }
        var kids = content.children, i, cls;
        for (i = 0; i < kids.length; i++) {
            cls = kids[i].className || '';
            if (typeof cls === 'string' && cls.indexOf('main-footer') >= 0) {
                footer = kids[i];
                return true;
            }
        }
        return false;
    }

    function sync() {
        if (!content || !footer) { if (!find()) { return; } }

        /* 先移除类、把页脚放回文档流再测量。
           否则在"已钉住"状态下测出的间隙恒为 0，会误判成不需要钉。 */
        content.classList.remove(CLASS);

        var gap = content.getBoundingClientRect().bottom -
                  footer.getBoundingClientRect().bottom;

        if (gap > 2) {                 // 页脚下方还有空白 -> 钉到底部填满
            content.classList.add(CLASS);
        }
    }

    function schedule() {
        clearTimeout(timer);
        timer = setTimeout(sync, 150);
    }

    function init() {
        if (!find()) { return; }
        sync();
        window.addEventListener('resize', schedule);
        window.addEventListener('orientationchange', schedule);
    }

    /* 本脚本位于 </footer> 之后，页脚与 .main-content 都已解析完，可直接执行 */
    init();
    /* 图片加载完页面高度会变，重新判定一次 */
    window.addEventListener('load', sync);
})();
</script>
<!-- ===== 页脚贴底修正 end ===== -->

<?php if (is_home() || is_front_page()): ?>
    <script type="text/javascript">
    $(document).ready(function() {
        setTimeout(function () { 
            if($('a.smooth[href="'+window.location.hash+'"]')[0]){
                $('a.smooth[href="'+window.location.hash+'"]').click();
            } else if(window.location.hash != ''){
                $("html, body").animate({
                    scrollTop: $(window.location.hash).offset().top - 80
                }, {
                    duration: 500,
                    easing: "swing"
                });
            }
        }, 300);
        $(document).on('click', '.has-sub', function(){
            var _this = $(this)
            if(!$(this).hasClass('expanded')) {
                setTimeout(function(){
                    _this.find('ul').attr("style","")
                }, 300);
            } else {
                $('.has-sub ul').each(function(id,ele){
                    var _that = $(this)
                    if(_this.find('ul')[0] != ele) {
                        setTimeout(function(){
                            _that.attr("style","")
                        }, 300);
                    }
                })
            }
        })
        $('.user-info-menu .hidden-xs').click(function(){
            if($('.sidebar-menu').hasClass('collapsed')) {
                $('.has-sub.expanded > ul').attr("style","")
            } else {
                $('.has-sub.expanded > ul').show()
            }
        })
        $("#main-menu li ul li").click(function() {
            $(this).siblings('li').removeClass('active'); // 删除其他兄弟元素的样式
            $(this).addClass('active'); // 添加当前元素的样式
        });
        $("a.smooth").click(function(ev) {
            ev.preventDefault();
            if($("#main-menu").hasClass('mobile-is-visible') != true)
                return;
            public_vars.$mainMenu.add(public_vars.$sidebarProfile).toggleClass('mobile-is-visible');
            ps_destroy();
            $("html, body").animate({
                scrollTop: $($(this).attr("href")).offset().top - 80
            }, {
                duration: 500,
                easing: "swing"
            });
        });
        return false;
    });

    var href = "";
    var pos = 0;
    $("a.smooth").click(function(e) {
        e.preventDefault();
        if($("#main-menu").hasClass('mobile-is-visible') === true)
            return;
        $("#main-menu li").each(function() {
            $(this).removeClass("active");
        });
        $(this).parent("li").addClass("active");
        href = $(this).attr("href");
        pos = $(href).position().top - 100;
        $("html,body").animate({
            scrollTop: pos
        }, 500);
    });
    </script>
<?php endif; ?>
<?php wp_footer(); ?>
<!-- 自定义代码 -->
<?php echo io_get_option('code_2_footer');?>
<!-- end 自定义代码 -->
</body>
</html>