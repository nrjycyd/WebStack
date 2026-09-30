<?php
/*
 * @Theme Name:WebStack
 * @Theme URI:https://www.iotheme.cn/
 * @Author: iowen
 * @Author URI: https://www.iowen.cn/
 * @Date: 2019-02-22 21:26:02
 * @LastEditors: iowen
 * @LastEditTime: 2022-07-25 18:11:27
 * @FilePath: \WebStack\templates\header-banner.php
 * @Description:
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/* =========================================================================
 *  汇率端点：由服务器向腾讯行情取「最新价 + 前收盘价」
 * =========================================================================
 *  为什么必须走服务端：腾讯行情接口校验 Referer，浏览器直连会被拦，
 *  而且跨域也不允许。由服务器请求则没有这两个问题。
 *
 *  为什么不用 frankfurter / exchangerate-api：它们给的是央行参考汇率与
 *  当前值，没有「前收盘价」；外汇是连续市场，基准应当是前一交易日收盘价。
 *
 *  接口返回形如（`~` 分隔）：
 *    310~美元人民币~USDCNY~6.7065~0~20260930005533~6.7100~6.7050~...
 *    索引：3=最新价 6=前收盘 12=涨跌额 13=涨跌幅(%)
 */

if ( ! defined( 'WEBSTACK_FX_ENDPOINT' ) ) {
    define( 'WEBSTACK_FX_ENDPOINT', true );
}
if ( ! defined( 'WEBSTACK_FX_CACHE_TTL' ) ) {
    define( 'WEBSTACK_FX_CACHE_TTL', 600 ); // 服务端缓存 10 分钟
}
if ( ! defined( 'WEBSTACK_FX_VERSION' ) ) {
    define( 'WEBSTACK_FX_VERSION', 'fx-tencent-v1' );
}

if ( ! function_exists( 'webstack_fx_http_get' ) ) {
    /** 带 Referer 的 GET（腾讯行情要求 Referer，否则 403）。 */
    function webstack_fx_http_get( $url ) {
        $args = array(
            'timeout'     => 8,
            'redirection' => 2,
            'headers'     => array(
                'Referer'         => 'https://gu.qq.com/',
                'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) WebStack-Theme',
                'Accept'          => '*/*',
                'Accept-Encoding' => 'identity',
            ),
        );
        $response = wp_remote_get( $url, $args );
        if ( is_wp_error( $response ) ) {
            return null;
        }
        if ( (int) wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return null;
        }
        $body = wp_remote_retrieve_body( $response );
        return is_string( $body ) ? $body : null;
    }
}

if ( ! function_exists( 'webstack_fx_parse' ) ) {
    /**
     * 解析腾讯行情返回：v_fxUSDCNY="310~美元人民币~USDCNY~6.7065~...";
     * 返回 array( 'last'=>float, 'prevClose'=>float, 'change'=>float, 'pct'=>float, 'raw'=>string )
     * 或 null。
     */
    function webstack_fx_parse( $body, $symbol ) {
        if ( ! is_string( $body ) || $body === '' ) {
            return null;
        }
        /*
         * 腾讯的实际格式是：v_fxUSDCNY="310~美元人民币~..."
         * 必须按「变量名=」定位，否则多符号响应里会永远取到第一个，
         * 出现「日元显示成美元」这类串号问题。
         */
        $pattern = '/v_' . preg_quote( $symbol, '/' ) . '="([^"]*)"/';
        if ( ! preg_match( $pattern, $body, $m ) ) {
            // 兼容不带 v_ 前缀的写法
            $pattern = '/' . preg_quote( $symbol, '/' ) . '="([^"]*)"/';
            if ( ! preg_match( $pattern, $body, $m ) ) {
                return null;
            }
        }
        $raw = $m[1];
        $f   = explode( '~', $raw );

        // 需要的四个字段都必须存在
        if ( count( $f ) < 14 ) {
            return null;
        }
        foreach ( array( 3, 6, 12, 13 ) as $idx ) {
            if ( ! isset( $f[ $idx ] ) || ! is_numeric( trim( $f[ $idx ] ) ) ) {
                return null;
            }
        }

        return array(
            'last'      => (float) $f[3],   // 最新价
            'prevClose' => (float) $f[6],   // 前收盘价（就是日环比基准）
            'change'    => (float) $f[12],  // 涨跌额
            'pct'       => (float) $f[13],  // 涨跌幅(%)
            'raw'       => $raw,
        );
    }
}

/* 汇率请求处理：返回 JSON 后立即结束，不再渲染页面 */
if ( WEBSTACK_FX_ENDPOINT && isset( $_GET['webstack_fx'] ) ) {
    $result = array( 'ok' => false, 'error' => 'unknown' );

    // 优先用 WordPress transient；不可用时退回文件缓存
    $cache_key = 'webstack_fx_tencent';
    $cache_dir = function_exists( 'get_temp_dir' ) ? get_temp_dir() : sys_get_temp_dir();
    $cache_file = rtrim( $cache_dir, '/\\' ) . '/webstack-fx-cache.json';

    $cached = function_exists( 'get_transient' ) ? get_transient( $cache_key ) : false;
    if ( ! is_array( $cached ) ) {
        $cached = false;
        if ( is_readable( $cache_file ) ) {
            $decoded = json_decode( (string) @file_get_contents( $cache_file ), true );
            if ( is_array( $decoded ) && isset( $decoded['time'] )
                && ( time() - (int) $decoded['time'] ) < WEBSTACK_FX_CACHE_TTL ) {
                $cached = $decoded;
            }
        }
    }

    if ( is_array( $cached ) && isset( $cached['data'] ) ) {
        if ( function_exists( 'set_transient' ) ) {
            set_transient( $cache_key, $cached, WEBSTACK_FX_CACHE_TTL );
        }
        $result = array( 'ok' => true, 'data' => $cached['data'], 'cached' => true );
    } else {
        // 一次请求同时取两个货币对
        $body = webstack_fx_http_get( 'https://qt.gtimg.cn/q=fxUSDCNY,fxJPYCNY' );

        $usd = webstack_fx_parse( $body, 'fxUSDCNY' );
        $jpy = webstack_fx_parse( $body, 'fxJPYCNY' );

        if ( $usd && $jpy ) {
            $data = array(
                'USD/CNY' => array(
                    'last'      => $usd['last'],
                    'prevClose' => $usd['prevClose'],
                    'pct'       => $usd['pct'],
                ),
                'JPY/CNY' => array(
                    'last'      => $jpy['last'],
                    'prevClose' => $jpy['prevClose'],
                    'pct'       => $jpy['pct'],
                ),
                'time' => time(),
            );
            $payload = array( 'time' => time(), 'data' => $data );
            if ( function_exists( 'set_transient' ) ) {
                set_transient( $cache_key, $payload, WEBSTACK_FX_CACHE_TTL );
            }
            @file_put_contents( $cache_file, wp_json_encode( $payload ) );
            $result = array( 'ok' => true, 'data' => $data, 'cached' => false );
        } else {
            // 失败时保留原始片段，便于排查（是没取到、还是字段变了）
            $result = array(
                'ok'    => false,
                'error' => '上游数据解析失败',
                'debug' => array(
                    'httpOk'    => is_string( $body ),
                    'bodyLen'   => is_string( $body ) ? strlen( $body ) : 0,
                    'rawSample' => is_string( $body ) ? substr( $body, 0, 200 ) : '',
                ),
            );
        }
    }

    if ( isset( $_GET['debug'] ) ) {
        $result['debug'] = array(
            'version'     => WEBSTACK_FX_VERSION,
            'obLevel'     => ob_get_level(),
            'headersSent' => headers_sent(),
        );
    }

    // 本文件在主题里载入很晚，此前可能已输出 HTML：先丢弃缓冲再单独输出 JSON
    for ( $i = ob_get_level(); $i > 0; $i-- ) {
        @ob_end_clean();
    }
    @header( 'Content-Type: application/json; charset=utf-8' );
    @header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
    @header( 'X-Content-Type-Options: nosniff' );
    @header( 'X-WebStack-Fx: 1' );

    echo wp_json_encode( $result );
    exit;
}
?>
<nav class="navbar user-info-navbar" role="navigation">
    <div class="navbar-content">
      <ul class="user-info-menu list-inline list-unstyled">
        <li class="hidden-xs">
            <a href="#" data-toggle="sidebar">
                <i class="fa fa-bars"></i>
            </a>
        </li>
        <!-- 天气 -->
        <!--
          说明：原「和风天气」简易插件（widget.heweather.net / widget.qweather.com）
          已于 2024-05-01 被官方整体停用、域名无法解析，故改用 Open-Meteo 开放接口
          （免费、无需注册、无需 API Key）：

            1) 定位：① myip.la → ② myip.ipip.net → ③ 最后兜底城市。
               前两个都是国内服务且支持跨域，纯前端可直连；全部静默，不弹权限框。
               不使用浏览器定位，避免权限框打断访客；
            2) 取数：Open-Meteo 实时天气 + 当天最高/最低温；
            3) 显示：图标 → 城市 → 温度 → 天气描述；
               悬停可见体感温度、湿度、风速、今日温区；
            4) 缓存：天气 15 分钟、定位 30 分钟，避免频繁请求。

          一般不需要改任何配置；只有两个定位源都不可用时，才会用到
          WEATHER_CONFIG.lastResort 里那个城市。
        -->
        <li id="site-weather" class="site-weather" style="display: none;">
          <span class="site-weather-icon" aria-hidden="true"></span>
          <span class="site-weather-city"></span>
          <span class="site-weather-temp"></span>
          <span class="site-weather-desc"></span>
        </li>
        <!-- 天气 end -->
      </ul>
      <ul class="user-info-menu list-inline list-unstyled">
        <li class="hidden-sm hidden-xs">
        <!-- 汇率 -->
        <!-- flex 纵向排列：日期固定独占一行，汇率在其下方一行内轮换 -->
        <!-- 右对齐：两个子元素都锚定右边缘，日期的位置就不会随汇率行的长度变化 -->
        <!-- 对齐要点：line-height 与 padding 需要和左侧天气模块保持一致
             （主题 nav.css 里同排元素都是 line-height:1），否则与汉堡图标错位。
             注意汇率行的 line-height 由下方 JS 注入、权重高于这里的行内样式。 -->
        <ul id="exchange-rate" class="exchange-rate-list" style="display: flex; flex-direction: column; align-items: flex-end; overflow: hidden; padding: 19px 20px; font-size: 15px; line-height: 1; text-align: right; margin-right: 20px; color: #666666;">
            <li id="date-info" class="date-info" style="list-style: none; white-space: nowrap; overflow: hidden; max-width: 100%;"></li>
            <!-- 汇率行：不写死 display，交由脚本置为 flex，这样父级在移动端仍能整体隐藏 -->
            <ul id="exchange-rate-row" class="exchange-rate-row" style="list-style: none; margin: 8px 0 0 0; padding: 0; white-space: nowrap; overflow: hidden; max-width: 100%;">
                <li id="usd-to-cny" class="exchange-rate-item" style="list-style: none;"></li>
                <li id="jpy-to-cny" class="exchange-rate-item" style="list-style: none; display: none;"></li>
            </ul>
        </ul>

        <script>
        (function () {
            var dateElement = document.getElementById('date-info');
            if (dateElement) {
                dateElement.innerHTML = new Date().toLocaleDateString(undefined, {
                    weekday: 'long', year: 'numeric', month: 'long', day: 'numeric'
                });
            }

            /* ===== 汇率 =====
               方案 2 + 4 + 7：
                 2) 行情牌价风：货币对代码 USD/CNY + 涨跌标记
                 4) 等宽数字（tabular-nums），轮换时数字不跳动
                 7) 每条数据用小圆角标签「框」起来
               基准货币为 USD，JPY/CNY 由交叉汇率算出（1 JPY = CNY / JPY），
               因此只需请求一次接口，原来分两次 + 5 秒等待的写法也一并去掉。
               配色遵循国内习惯：涨红、跌绿。 */

            var rateRow = document.getElementById('exchange-rate-row');
            if (!rateRow) { return; }

            /* ---------- 样式 ---------- */
            var style = document.createElement('style');
            style.textContent =
                /* 汇率行：纯文字，不加背景/边框/内边距，与日期左对齐 */
                '.exchange-rate-row{display:flex;flex-direction:row;align-items:baseline;gap:8px;' +
                'padding:0;background:none;border:0;}' +
                '.rate-line{align-items:baseline;gap:7px;color:#666;' +
                'white-space:nowrap;font-size:13px;line-height:1;}' +
                /* 货币对代码不加粗，靠字距与颜色区分 */
                '.rate-line .rate-code{font-weight:400;letter-spacing:.4px;color:#555;}' +
                /* 4) 等宽数字：切换货币对时数字不跳动 */
                '.rate-line .rate-value{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;' +
                'font-variant-numeric:tabular-nums;font-size:14px;font-weight:400;color:#333;}' +
                '.rate-line .rate-change{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;' +
                'font-variant-numeric:tabular-nums;font-size:12px;}' +
                /* 2) 涨跌配色：遵循国内习惯，涨红跌绿 */
                '.rate-line .rate-change.up{color:#d93025;}' +
                '.rate-line .rate-change.down{color:#188038;}' +
                '.rate-line .rate-change.flat{color:#9aa0a6;}' +
                /* 过渡：切换货币对时淡出旧卡片、淡入新卡片。
                   用 flex + min-width 而不是绝对定位，避免卡片互相重叠；
                   min-width 同时保证两个货币对切换时左侧起点不跳动。
                   选择器带上行容器以压过上面的 .rate-line 规则（同权重时后者会赢）。 */
                '.exchange-rate-row .exchange-rate-item{min-width:150px;transition:opacity .25s ease;}' +
                '.exchange-rate-row .exchange-rate-item.is-fading{opacity:0;}';
            document.head.appendChild(style);

            /* 涨跌幅直接用接口给的值。
               腾讯行情返回的「涨跌幅」就是相对前收盘价算好的，所以不再自己
               用汇率相除 —— 那样会引入交叉汇率的取整误差。 */
            function trendOf(pct) {
                if (typeof pct !== 'number' || !isFinite(pct)) { return null; }
                if (Math.abs(pct) < 0.005) { return { text: '0.00%', cls: 'flat' }; }
                return {
                    text: (pct > 0 ? '▲ ' : '▼ ') + Math.abs(pct).toFixed(2) + '%',
                    cls: pct > 0 ? 'up' : 'down'
                };
            }
            // 金额格式化：普通数值保留 4 位小数（与行情一致）；
            // 小于 1 的（如 1 日元 ≈ 0.0425）需要 4 位有效小数，否则只看到 0.0425 尚可但 0.0043 会截断
            function fmtRate(v) {
                var n = Number(v);
                if (!isFinite(n)) { return '--'; }
                if (n === 0) { return '0.0000'; }
                if (Math.abs(n) >= 1) { return n.toFixed(4); }
                if (Math.abs(n) >= 0.01) { return n.toFixed(4); }   // 0.0425
                if (Math.abs(n) >= 0.0001) { return n.toFixed(5); } // 0.00425
                return n.toFixed(6);
            }

            /* ---------- 渲染一行牌价 ----------
               注意：只有第一行初始可见，其余保持隐藏，由轮换逻辑负责显示。
               不要在这里对所有行都设 display，否则会同时显示多个货币对。 */
            function renderCard(item, quote, visible, source) {
                var box = document.getElementById(item.id);
                if (!box) { return; }
                if (!quote || typeof quote.last !== 'number' || !isFinite(quote.last)) {
                    showUnavailable(item, visible);
                    return;
                }

                var change = trendOf(quote.pct);

                box.className = 'rate-line';
                box.style.display = visible ? 'inline-flex' : 'none';
                while (box.firstChild) { box.removeChild(box.firstChild); }

                // 悬停显示单位、前收盘价，并注明数据来源（降级时能看出来）
                var unit = item.unit ? '（' + item.unit + '）' : '';
                var tip = item.code + ' ' + fmtRate(quote.last) + unit;
                if (typeof quote.prevClose === 'number' && isFinite(quote.prevClose)) {
                    tip += '\n较前收盘 ' + fmtRate(quote.prevClose) +
                        (change ? '（' +
                            (change.cls === 'up' ? '上涨 ' : (change.cls === 'down' ? '下跌 ' : '持平 ')) +
                            change.text.replace(/[▲▼]\s*/, '') + '）' : '');
                }
                var src = source || quote.source;
                if (src) { tip += '\n数据来源：' + src; }
                box.title = tip;

                var codeEl = document.createElement('span');
                codeEl.className = 'rate-code';
                codeEl.appendChild(document.createTextNode(item.code));

                var valueEl = document.createElement('span');
                valueEl.className = 'rate-value';
                valueEl.appendChild(document.createTextNode(fmtRate(quote.last)));

                box.appendChild(codeEl);
                box.appendChild(valueEl);

                // 只有拿到可信基线时才显示涨跌，避免长期停在「0.00%」的假信息
                if (change) {
                    var chgEl = document.createElement('span');
                    chgEl.className = 'rate-change ' + change.cls;
                    chgEl.appendChild(document.createTextNode(change.text));
                    box.appendChild(chgEl);
                }
            }

            function showUnavailable(item, visible) {
                var box = document.getElementById(item.id);
                if (!box) { return; }
                box.className = 'rate-line';
                box.style.display = visible ? 'inline-flex' : 'none';
                while (box.firstChild) { box.removeChild(box.firstChild); }
                var codeEl = document.createElement('span');
                codeEl.className = 'rate-code';
                codeEl.appendChild(document.createTextNode(item.code));
                var valueEl = document.createElement('span');
                valueEl.className = 'rate-change flat';
                valueEl.appendChild(document.createTextNode('暂不可用'));
                box.appendChild(codeEl);
                box.appendChild(valueEl);
            }

            /*
             * 注意单位：腾讯行情的日元报价是国内惯例的「百日元人民币」
             * （100 日元合多少元）。而货币对 JPY/CNY 的标准含义是「1 日元」，
             * 两者相差 100 倍，所以这里除以 100，让数值与标签一致。
             * 涨跌幅是比率，不受 100 倍影响，无需换算。
             */
            var RATES = [
                { code: 'USD/CNY', key: 'USD/CNY', id: 'usd-to-cny', unit: '1 美元', scale: 1 },
                { code: 'JPY/CNY', key: 'JPY/CNY', id: 'jpy-to-cny', unit: '1 日元', scale: 0.01, cross: true }
            ];

            // 服务端汇率端点（腾讯行情需校验 Referer，浏览器直连会被拦）
            var FX_ENDPOINT = (function () {
                var url = window.location.href.split('#')[0].split('?')[0];
                return url + '?webstack_fx=1';
            })();

            /* ---------- 轮换（只在汇率行内，带淡入淡出过渡） ---------- */
            var items = rateRow.querySelectorAll('.exchange-rate-item');
            var index = 0;
            var rotateTimer = null;
            var swapping = false;

            function swapTo(next) {
                var from = items[index];
                var to = items[next];
                if (!from || !to || from === to) { return; }

                swapping = true;
                from.classList.add('is-fading');   // 先淡出

                setTimeout(function () {
                    from.style.display = 'none';
                    from.classList.remove('is-fading');
                    to.style.display = 'inline-flex';
                    to.classList.add('is-fading');  // 从透明开始
                    // 下一帧再移除，让浏览器有机会应用初始透明度，过渡才会发生
                    setTimeout(function () {
                        to.classList.remove('is-fading');
                        swapping = false;
                    }, 20);
                }, 250); // 与 CSS 的 opacity .25s 对齐
            }

            function startRotate() {
                if (rotateTimer || items.length < 2) { return; }
                rotateTimer = setInterval(function () {
                    if (swapping) { return; }
                    swapTo((index + 1) % items.length);
                    index = (index + 1) % items.length;
                }, 5000);
            }

            /* ---------- 取数并渲染 ----------
               两个数据源，各有取舍：
                 ① 服务端端点（腾讯行情）：真正的前收盘价，但依赖服务器能连上腾讯。
                 ② 浏览器直连 frankfurter：欧洲央行参考汇率（不是前收盘价），
                    但前端可直接访问，作为 ① 失败时的兜底，避免整块汇率空白。
               哪个源成功就在悬停里注明，不做隐藏的降级。 */
            function loadRates() {
                fetch(FX_ENDPOINT, { credentials: 'omit' })
                    .then(function (response) {
                        if (!response.ok) { throw new Error('HTTP ' + response.status); }
                        return response.json();
                    })
                    .then(function (resp) {
                        if (!resp || resp.ok !== true || !resp.data) {
                            throw new Error((resp && resp.error) ? resp.error : '返回数据格式不正确');
                        }
                        var data = resp.data, got = 0;
                        for (var k = 0; k < RATES.length; k++) {
                            var item = RATES[k];
                            var q = data[item.key]; // 端点用标准货币对作键
                            if (q && typeof q.last === 'number') {
                                got++;
                                // 按 scale 归一到「1 单位」口径（日元：百日元 -> 1 日元）
                                // 涨跌幅是比率，不受 100 倍影响，原样使用
                                renderCard(item, {
                                    last: q.last * item.scale,
                                    prevClose: (typeof q.prevClose === 'number')
                                        ? q.prevClose * item.scale : undefined,
                                    pct: q.pct
                                }, k === 0, '腾讯行情');
                            }
                        }
                        if (!got) { throw new Error('返回数据里没有可用汇率'); }
                        rateRow.style.display = 'flex';
                        rateRow.style.justifyContent = 'flex-end'; // 与日期一样右对齐
                        startRotate();
                    })
                    .catch(function (error) {
                        console.warn('服务端汇率不可用，改用浏览器直连备用源:', error);
                        loadRatesFallback();
                    });
            }

            /* 备用源：frankfurter（ECB 参考汇率）。无前收盘价可用，
               所以基准取序列里前一个有数据的交易日，并在悬停里写明来源。 */
            function loadRatesFallback() {
                var end = new Date();
                var start = new Date(end.getTime() - 10 * 24 * 3600 * 1000);
                var url = 'https://api.frankfurter.dev/v1/' + isoDate(start) + '..' + isoDate(end) +
                    '?base=USD&symbols=CNY,JPY';

                fetch(url, { credentials: 'omit' })
                    .then(function (response) {
                        if (!response.ok) { throw new Error('HTTP ' + response.status); }
                        return response.json();
                    })
                    .then(function (data) {
                        var series = data && data.rates;
                        if (!series) { throw new Error('备用源返回格式不正确'); }
                        var dates = Object.keys(series).sort();
                        if (!dates.length) { throw new Error('备用源数据为空'); }

                        var curDate = dates[dates.length - 1];
                        var cur = series[curDate];
                        var prev = null, prevDate = '';
                        for (var i = dates.length - 2; i >= 0; i--) {
                            var r = series[dates[i]];
                            if (r && typeof r.CNY === 'number' && typeof r.JPY === 'number') {
                                prev = r; prevDate = dates[i]; break;
                            }
                        }
                        if (!cur || typeof cur.CNY !== 'number' || typeof cur.JPY !== 'number') {
                            throw new Error('备用源缺少 CNY 或 JPY');
                        }

                        /* 交叉汇率：1 JPY = CNY / JPY（与标签 JPY/CNY 一致，不再 ×100。
                           主源的百日元口径由 scale 统一处理，这里保持原始比率）。 */
                        var pick = function (r, item) {
                            if (!r) { return null; }
                            return item.cross ? (r.CNY / r.JPY) : r.CNY;
                        };
                        var mk = function (item) {
                            var last = pick(cur, item), pc = pick(prev, item);
                            var pct = null;
                            if (typeof pc === 'number' && pc) { pct = (last - pc) / pc * 100; }
                            return {
                                last: last,
                                prevClose: (typeof pc === 'number') ? pc : undefined,
                                pct: pct
                            };
                        };

                        renderCard(RATES[0], mk(RATES[0]), true, 'ECB 参考汇率');
                        renderCard(RATES[1], mk(RATES[1]), false, 'ECB 参考汇率');
                        rateRow.style.display = 'flex';
                        rateRow.style.justifyContent = 'flex-end'; // 与日期一样右对齐
                        startRotate();
                    })
                    .catch(function (error) {
                        console.error('备用源也不可用:', error);
                        for (var i = 0; i < RATES.length; i++) {
                            showUnavailable(RATES[i], i === 0);
                        }
                        rateRow.style.display = 'flex';
                        rateRow.style.justifyContent = 'flex-end'; // 与日期一样右对齐
                        startRotate();
                    });
            }

            // YYYY-MM-DD（备用源按日期查询用）
            function isoDate(d) {
                var m = d.getUTCMonth() + 1, day = d.getUTCDate();
                return d.getUTCFullYear() + '-' + (m < 10 ? '0' + m : m) + '-' + (day < 10 ? '0' + day : day);
            }

            loadRates();
            setInterval(loadRates, 5 * 60 * 1000); // 每 5 分钟刷新一次汇率
        })();
        </script>
        <!-- 汇率 end -->

            <!-- <a href="https://github.com/owen0o0/WebStack" target="_blank"><i class="fa fa-github"></i> GitHub</a> -->
        </li>
      </ul>
    </div>
</nav>

<script>
/* ===== 天气模块（Open-Meteo，无需 API Key） ===== */
(function () {
    'use strict';

    /*
     * 定位策略（静默，不弹任何权限框，全部按 IP 自动判断）：
     *
     *   ① myip.la          国内服务、支持跨域，直接返回经纬度 + 中文地名
     *   ② myip.ipip.net    国内服务、支持跨域，返回纯文本地域（无经纬度，
     *                      需再用城市名查一次坐标）
     *   ③ 最后兜底          两个都失败时用的城市（只为避免天气空白，
     *                      不是主用路径；IP 定位正常时用不到它）
     *
     * 不使用浏览器定位：它会弹权限框打断访客，体验代价高于精度收益。
     */
    var WEATHER_CONFIG = {
        // 仅当上面两个 IP 定位服务都失败时才使用
        lastResort: { name: '北京', latitude: 39.9075, longitude: 116.3972 },
        refreshMinutes: 15,
        cacheMinutes: 15,
        locateTtlMinutes: 30,
        lang: 'zh'
    };

    var box = document.getElementById('site-weather');
    if (!box) { return; }

    var elIcon = box.querySelector('.site-weather-icon');
    var elTemp = box.querySelector('.site-weather-temp');
    var elCity = box.querySelector('.site-weather-city');
    var elDesc = box.querySelector('.site-weather-desc');

    /* ---------- 样式（跟随主题配色，尽量不依赖主题 CSS） ----------
       显示顺序：图标 → 城市 → 温度 → 天气描述。

       ⚠️ 本段的设计原则：**不要跟主题的导航栏机制对抗**。
       主题 nav.css 已有：
         .user-info-navbar .user-info-menu li{line-height:1;display:table-cell;vertical-align:middle}
       汉堡图标就是同一 <ul> 里的 <li>，走的正是这条规则。
       所以这里**不要**给容器加 display:inline-flex / flex 相关属性 ——
       那会把元素从 table-cell 机制里拽出来，失去 vertical-align:middle 的
       居中约束，表现就是文字比左侧图标偏低（反复出现过的错位）。
       容器只需少量内边距，垂直居中交给主题即可。

       内边距保持 10px 级（窄屏 6px）。不要放大到 31px 去"对齐"汉堡图标 ——
       31px 是给纯图标按钮的，套到一行文字上会把这一栏撑高而变形。
       行高保持 1，与主题一致；放大（如 1.6）会让中文行盒多出空间而显低。 */
    var css = document.createElement('style');
    css.textContent =
        '#site-weather.site-weather{padding:10px 12px;font-size:15px;line-height:1;' +
        'color:#666;white-space:nowrap;cursor:default;}' +
        '.site-weather .site-weather-icon{vertical-align:middle;margin-right:3px;}' +
        '.site-weather .site-weather-icon svg{display:inline-block;vertical-align:middle;}' +
        '.site-weather .site-weather-city{color:#666;margin-right:1px;}' +
        /* 温度是主信息：与城市同字号，靠颜色加深做区分（不加粗） */
        '.site-weather .site-weather-temp{font-size:15px;font-weight:400;color:#333;' +
        'font-variant-numeric:tabular-nums;}' +
        /* 描述是次要信息：小一号、更浅，与温度留出呼吸 */
        '.site-weather .site-weather-desc{color:#8a8a8a;font-size:14px;margin-left:5px;}' +
        '.site-weather.is-loading{opacity:.6;}' +
        '@media (max-width:767px){' +
        '#site-weather.site-weather{padding:10px 6px;}' +
        '.site-weather .site-weather-desc{display:none;}}';
    document.head.appendChild(css);

    /* ---------- 小工具 ---------- */
    function store(key, value, ttlMinutes) {
        try {
            sessionStorage.setItem(key, JSON.stringify({
                t: Date.now(),
                ttl: ttlMinutes * 60 * 1000,
                v: value
            }));
        } catch (e) { /* 隐私模式等场景忽略 */ }
    }

    function load(key) {
        try {
            var raw = sessionStorage.getItem(key);
            if (!raw) { return null; }
            var o = JSON.parse(raw);
            if (!o || (Date.now() - o.t) > o.ttl) { return null; }
            return o.v;
        } catch (e) { return null; }
    }

    function fetchJSON(url, timeoutMs) {
        return new Promise(function (resolve, reject) {
            var timer = null;
            var ctrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
            if (ctrl) {
                timer = setTimeout(function () { ctrl.abort(); }, timeoutMs || 8000);
            }
            fetch(url, ctrl ? { signal: ctrl.signal } : undefined)
                .then(function (r) {
                    if (!r.ok) { throw new Error('HTTP ' + r.status); }
                    return r.json();
                })
                .then(function (data) {
                    if (timer) { clearTimeout(timer); }
                    resolve(data);
                })
                .catch(function (err) {
                    if (timer) { clearTimeout(timer); }
                    reject(err);
                });
        });
    }

    // 同上，但取纯文本（ipip.net 返回的是文本而不是 JSON）
    function fetchText(url, timeoutMs) {
        return new Promise(function (resolve, reject) {
            var timer = null;
            var ctrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
            if (ctrl) { timer = setTimeout(function () { ctrl.abort(); }, timeoutMs || 8000); }
            fetch(url, ctrl ? { signal: ctrl.signal, credentials: 'omit' } : { credentials: 'omit' })
                .then(function (r) {
                    if (!r.ok) { throw new Error('HTTP ' + r.status); }
                    return r.text();
                })
                .then(function (text) {
                    if (timer) { clearTimeout(timer); }
                    resolve(text);
                })
                .catch(function (err) {
                    if (timer) { clearTimeout(timer); }
                    reject(err);
                });
        });
    }

    /* ---------- WMO 天气代码 -> 图标 + 中文描述 ---------- */
    var ICONS = {
        sun: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#f5a623" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="4.2" fill="#f5a623" stroke="none"/><path d="M12 2.5v2.2M12 19.3v2.2M2.5 12h2.2M19.3 12h2.2M5.2 5.2l1.6 1.6M17.2 17.2l1.6 1.6M18.8 5.2l-1.6 1.6M6.8 17.2l-1.6 1.6"/></svg>',
        moon: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#7a8ba6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 14.5A8.5 8.5 0 0 1 9.5 4a8.5 8.5 0 1 0 10.5 10.5z" fill="#dfe6f0"/></svg>',
        cloudSun: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#f5a623" stroke-width="2" stroke-linecap="round"><circle cx="8" cy="7.5" r="3" fill="#f5a623" stroke="none"/><path d="M8 1.8v1.4M1.8 7.5h1.4M3.6 3.1l1 1M12.4 3.1l-1 1"/><path d="M9 17.5h8.2a3.3 3.3 0 0 0 .3-6.6 4.6 4.6 0 0 0-8.8 1.1A2.8 2.8 0 0 0 9 17.5z" fill="#cfd8e3" stroke="#9aa8b8"/></svg>',
        cloudMoon: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#9aa8b8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13.5 2.6a6.4 6.4 0 0 0 7.9 7.9 6.4 6.4 0 1 1-7.9-7.9z" fill="#dfe6f0"/><path d="M8.5 20h8.2a3.3 3.3 0 0 0 .3-6.6 4.6 4.6 0 0 0-8.8 1.1A2.8 2.8 0 0 0 8.5 20z" fill="#cfd8e3" stroke="#9aa8b8"/></svg>',
        cloud: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#9aa8b8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 18.5h9.2a3.8 3.8 0 0 0 .3-7.6 5.3 5.3 0 0 0-10.1 1.3A3.2 3.2 0 0 0 7 18.5z" fill="#cfd8e3"/></svg>',
        fog: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#9aa8b8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 12.5h9.2a3.8 3.8 0 0 0 .3-7.6 5.3 5.3 0 0 0-10.1 1.3A3.2 3.2 0 0 0 7 12.5z" fill="#cfd8e3"/><path d="M3.5 16.5h17M5.5 20h13"/></svg>',
        rain: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#4a90d9" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 14.5h9.2a3.8 3.8 0 0 0 .3-7.6 5.3 5.3 0 0 0-10.1 1.3A3.2 3.2 0 0 0 7 14.5z" fill="#cfd8e3" stroke="#9aa8b8"/><path d="M8.5 17.5l-1 3M12.5 17.5l-1 3M16.5 17.5l-1 3"/></svg>',
        snow: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#7fb8e0" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 13.5h9.2a3.8 3.8 0 0 0 .3-7.6 5.3 5.3 0 0 0-10.1 1.3A3.2 3.2 0 0 0 7 13.5z" fill="#dfe6f0" stroke="#9aa8b8"/><path d="M9 17.5v3M7.7 18.3l2.6 1.4M12 17.5v3M10.7 18.3l2.6 1.4M15 17.5v3M13.7 18.3l2.6 1.4"/></svg>',
        thunder: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#4a90d9" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 13.5h9.2a3.8 3.8 0 0 0 .3-7.6 5.3 5.3 0 0 0-10.1 1.3A3.2 3.2 0 0 0 7 13.5z" fill="#cfd8e3" stroke="#9aa8b8"/><path d="M13 15.5l-3 4.5h2.6l-.6 3.5 3-4.5h-2.6z" fill="#f5a623" stroke="#f5a623"/></svg>'
    };

    function describe(code, isDay) {
        var table = {
            0: ['晴', 'sun', 'moon'],
            1: ['晴间多云', 'cloudSun', 'cloudMoon'],
            2: ['多云', 'cloudSun', 'cloudMoon'],
            3: ['阴', 'cloud', 'cloud'],
            45: ['有雾', 'fog', 'fog'],
            48: ['冻雾', 'fog', 'fog'],
            51: ['小毛毛雨', 'rain', 'rain'],
            53: ['毛毛雨', 'rain', 'rain'],
            55: ['浓毛毛雨', 'rain', 'rain'],
            56: ['冻毛毛雨', 'rain', 'rain'],
            57: ['浓冻毛毛雨', 'rain', 'rain'],
            61: ['小雨', 'rain', 'rain'],
            63: ['中雨', 'rain', 'rain'],
            65: ['大雨', 'rain', 'rain'],
            66: ['冻雨', 'rain', 'rain'],
            67: ['强冻雨', 'rain', 'rain'],
            71: ['小雪', 'snow', 'snow'],
            73: ['中雪', 'snow', 'snow'],
            75: ['大雪', 'snow', 'snow'],
            77: ['米雪', 'snow', 'snow'],
            80: ['阵雨', 'rain', 'rain'],
            81: ['强阵雨', 'rain', 'rain'],
            82: ['暴雨', 'rain', 'rain'],
            85: ['阵雪', 'snow', 'snow'],
            86: ['强阵雪', 'snow', 'snow'],
            95: ['雷阵雨', 'thunder', 'thunder'],
            96: ['雷阵雨伴冰雹', 'thunder', 'thunder'],
            99: ['强雷阵雨伴冰雹', 'thunder', 'thunder']
        };
        var entry = table[code] || ['未知', 'cloud', 'cloud'];
        return { text: entry[0], icon: isDay ? entry[1] : entry[2] };
    }

    /* ---------- 定位 ---------- */
    // 去掉行政区后缀，让显示更简洁（深圳市 -> 深圳）
    function shortCityName(name) {
        if (!name) { return ''; }
        return String(name).replace(/(特别行政区|自治州|地区|盟|市|县|区)$/u, '').trim() || String(name);
    }

    /*
     * 定位源 ①：myip.la（国内服务，由 IPIP.NET 赞助）。
     *
     * 为什么首选它：
     *   · 支持跨域（Access-Control-Allow-Origin: *），纯前端可直连，不必架服务端代理
     *   · 国内服务、免费不限次、支持 IPv6
     *   · 一次就返回中文地名 + 经纬度，不需要再做城市名转坐标
     *
     * 两个注意点：
     *   1) 它返回的永远是「请求者自己」的位置，不接受指定 IP —— 而这里正好
     *      由访客浏览器直接请求，拿到的天然就是访客自己的位置；
     *   2) 它的 OPTIONS 预检返回 404，所以只能用不带自定义头的简单请求，
     *      普通的 fetch(url) 不会触发预检，符合要求。
     */
    function ipLocation1() {
        return fetchJSON('https://api.myip.la/cn?json', 7000).then(function (d) {
            var loc = d && d.location;
            if (!loc) { throw new Error('myip.la 返回缺少 location'); }
            var lat = parseFloat(loc.latitude);
            var lon = parseFloat(loc.longitude);
            if (isNaN(lat) || isNaN(lon)) { throw new Error('myip.la 未返回经纬度'); }
            return {
                latitude: lat,
                longitude: lon,
                name: shortCityName(loc.city || loc.province || ''),
                source: 'myip.la'
            };
        });
    }

    /*
     * 城市名 → 经纬度（Open-Meteo 地理编码，免费、支持跨域、支持中文地名）。
     * 备用定位源只给地名不给坐标，所以需要这一层换算。
     */
    function geocodeCity(name) {
        if (!name) { return Promise.reject(new Error('城市名为空')); }
        var url = 'https://geocoding-api.open-meteo.com/v1/search?name=' +
            encodeURIComponent(name) + '&count=1&language=zh&format=json';
        return fetchJSON(url, 7000).then(function (d) {
            var r = d && d.results && d.results[0];
            if (!r || typeof r.latitude !== 'number') { throw new Error('未查到「' + name + '」'); }
            return { latitude: r.latitude, longitude: r.longitude, name: r.name || name };
        });
    }

    /*
     * 解析 ipip.net 的纯文本返回。
     * 形如：当前 IP：1.2.3.4  来自于：中国 A省 乙城  运营商A
     * 地域段结构为「国家 省份 [城市 [区县]] 运营商」，共 4 段时城市在倒数第二段。
     */
    function parseIpipText(text) {
        var m = /来自于[：:]\s*(.+)/.exec(String(text || ''));
        if (!m) { throw new Error('ipip.net 返回格式无法识别'); }
        var parts = m[1].trim().split(/[\s\u3000]+/).filter(function (s) { return s; });
        if (parts.length < 3) { throw new Error('ipip.net 地域字段不足'); }
        return {
            country: parts[0],
            province: parts[1],
            city: parts.length >= 4 ? parts[parts.length - 2] : '',
            isp: parts[parts.length - 1]
        };
    }

    /*
     * 定位源 ②：myip.ipip.net（国内服务、支持跨域）。
     * 它只返回纯文本地域、没有经纬度，所以要再用城市名查一次坐标。
     */
    function ipLocation2() {
        return fetchText('https://myip.ipip.net', 7000)
            .then(function (text) {
                var info = parseIpipText(text);
                // 优先用城市，没有城市就用省份（省份可能查不到，交给上层兜底）
                var lookup = info.city || info.province;
                return geocodeCity(lookup).then(function (geo) {
                    return {
                        latitude: geo.latitude,
                        longitude: geo.longitude,
                        name: shortCityName(info.city || geo.name || ''),
                        source: 'ipip.net'
                    };
                });
            });
    }

    /*
     * 定位：① myip.la → ② myip.ipip.net → ③ 最后兜底城市。
     * 全部静默，不弹任何权限框；最后一步只为避免天气空白。
     */
    function locate() {
        return ipLocation1()
            .catch(function (e1) {
                console.warn('myip.la 定位失败，改用 myip.ipip.net：', e1 && e1.message ? e1.message : e1);
                return ipLocation2();
            })
            .catch(function (e2) {
                console.warn('ipip.net 定位也失败，使用最后兜底城市：', e2 && e2.message ? e2.message : e2);
                var lr = WEATHER_CONFIG.lastResort;
                return { latitude: lr.latitude, longitude: lr.longitude, name: lr.name, source: 'lastResort' };
            });
    }

    // 带缓存的定位，避免每次刷新都重复请求
    function locateCached() {
        var cached = load('site_weather_loc');
        if (cached && typeof cached.latitude === 'number' && typeof cached.longitude === 'number') {
            return Promise.resolve(cached);
        }
        return locate().then(function (loc) {
            store('site_weather_loc', loc, WEATHER_CONFIG.locateTtlMinutes);
            return loc;
        });
    }

    /* ---------- 渲染 ---------- */
    function render(loc, weather) {
        var cur = weather.current;
        var daily = weather.daily || {};
        var info = describe(cur.weather_code, cur.is_day === 1);

        elIcon.innerHTML = ICONS[info.icon] || ICONS.cloud;
        if (elTemp) { elTemp.textContent = Math.round(cur.temperature_2m) + '°C'; }
        if (elCity) { elCity.textContent = loc.name || ''; }
        if (elDesc) { elDesc.textContent = info.text; }

        var tMax = (daily.temperature_2m_max && daily.temperature_2m_max.length) ? Math.round(daily.temperature_2m_max[0]) + '°' : '--';
        var tMin = (daily.temperature_2m_min && daily.temperature_2m_min.length) ? Math.round(daily.temperature_2m_min[0]) + '°' : '--';

        box.title = (loc.name ? loc.name + '　' : '') + info.text +
            '\n温度：' + Math.round(cur.temperature_2m) + '°C（体感 ' + Math.round(cur.apparent_temperature) + '°C）' +
            '\n今日：' + tMin + ' ~ ' + tMax +
            '\n湿度：' + Math.round(cur.relative_humidity_2m) + '%' +
            '\n风速：' + Math.round(cur.wind_speed_10m) + ' km/h' +
            '\n数据：Open-Meteo';

        box.classList.remove('is-loading');
        box.style.display = '';
    }

    function loadWeather(loc) {
        var key = 'site_weather_data_' + loc.latitude.toFixed(2) + '_' + loc.longitude.toFixed(2);
        var cached = load(key);
        if (cached) { return Promise.resolve(cached); }

        var url = 'https://api.open-meteo.com/v1/forecast?latitude=' + loc.latitude +
            '&longitude=' + loc.longitude +
            '&current=temperature_2m,relative_humidity_2m,apparent_temperature,is_day,weather_code,wind_speed_10m' +
            '&daily=temperature_2m_max,temperature_2m_min' +
            '&timezone=auto&forecast_days=1&lang=' + encodeURIComponent(WEATHER_CONFIG.lang);

        return fetchJSON(url, 9000).then(function (data) {
            if (!data || !data.current) { throw new Error('bad weather payload'); }
            store(key, data, WEATHER_CONFIG.cacheMinutes);
            return data;
        });
    }

    function run() {
        box.classList.add('is-loading');
        box.style.display = '';
        locateCached()
            .then(function (loc) {
                return loadWeather(loc).then(function (w) { render(loc, w); });
            })
            .catch(function (err) {
                console.error('天气模块加载失败:', err);
                // 失败时隐藏天气区域，避免留下空白占位
                box.style.display = 'none';
            });
    }

    run();
    setInterval(run, Math.max(1, WEATHER_CONFIG.refreshMinutes) * 60 * 1000);
})();
/* ===== 天气模块 end ===== */
</script>
