<?php
require_once 'config.php';
require_once 'includes/header.php';

// 讀取所有 site_settings → $S 陣列
$S = [];
try {
    $rows = $pdo->query("SELECT setting_key, setting_value FROM site_settings")->fetchAll();
    foreach ($rows as $r) { $S[$r['setting_key']] = $r['setting_value']; }
} catch (Exception $e) {}
function s($key, $default = '') { global $S; return $S[$key] ?? $default; }

// 讀取有效公告
$announcements = [];
$pinned_announcements = [];
try {
    $now_dt = date('Y-m-d H:i:s');
    
    $stmt = $pdo->prepare("
        SELECT * FROM announcements 
        WHERE is_active = 1 
        AND (start_date IS NULL OR CONCAT(start_date, ' ', COALESCE(start_time, '00:00:00')) <= ?)
        AND (end_date IS NULL OR CONCAT(end_date, ' ', COALESCE(end_time, '23:59:59')) >= ?)
        ORDER BY created_at DESC
    ");
    $stmt->execute([$now_dt, $now_dt]);
    $announcements = $stmt->fetchAll();
    
    // 讀取首頁置頂公告（最多 3 則）
    $stmt = $pdo->prepare("
        SELECT * FROM announcements 
        WHERE is_active = 1 AND is_pinned_home = 1
        AND (start_date IS NULL OR CONCAT(start_date, ' ', COALESCE(start_time, '00:00:00')) <= ?)
        AND (end_date IS NULL OR CONCAT(end_date, ' ', COALESCE(end_time, '23:59:59')) >= ?)
        ORDER BY created_at DESC
        LIMIT 3
    ");
    $stmt->execute([$now_dt, $now_dt]);
    $pinned_announcements = $stmt->fetchAll();
    
} catch (Exception $e) {
    error_log("Error loading announcements: " . $e->getMessage());
}

// 讀取 CTA 按鈕
$cta_buttons = [];
try {
    $cta_buttons = $pdo->query("SELECT * FROM homepage_cta_buttons WHERE is_active = 1 ORDER BY sort_order, id")->fetchAll();
} catch (Exception $e) {
    error_log("Error loading CTA buttons: " . $e->getMessage());
}

// 讀取 9 宮格自訂項目
$grid_items = [];
try {
    $grid_items = $pdo->query("SELECT * FROM homepage_grid_items WHERE is_active = 1 ORDER BY grid_position, id")->fetchAll();
} catch (Exception $e) {
    error_log("Error loading grid items: " . $e->getMessage());
}

// 讀取教練資料（包含區域）
$coaches = [];
try {
    $coaches = $pdo->query("
        SELECT u.nickname, u.name, u.avatar, u.region, cp.bio, cp.specialty, cp.coach_level, cp.years_experience
        FROM users u 
        JOIN coach_profiles cp ON u.id = cp.user_id 
        WHERE u.role = 'coach' AND u.is_active = 1 AND u.is_approved = 1
        ORDER BY cp.years_experience DESC
    ")->fetchAll();
} catch (Exception $e) {
    error_log("Error loading coaches: " . $e->getMessage());
}

// 教練等級直接使用資料庫中的文字

// 讀取公告類型對應
$ann_type_map = [];
try {
    $ann_types = $pdo->query("SELECT * FROM announcement_types ORDER BY sort_order")->fetchAll();
    foreach ($ann_types as $at) { $ann_type_map[$at['slug']] = $at; }
} catch (Exception $e) {
    error_log("Error loading announcement types: " . $e->getMessage());
}

// 讀取首頁自訂區塊
$homepage_blocks = [];
try {
    $homepage_blocks = $pdo->query("SELECT * FROM homepage_blocks WHERE is_active = 1 ORDER BY sort_order, id")->fetchAll();
} catch (Exception $e) {
    error_log("Error loading homepage blocks: " . $e->getMessage());
}

// 讀取常見問題
$faqs = [];
try {
    $faqs = $pdo->query("SELECT * FROM faqs WHERE is_active = 1 ORDER BY is_pinned DESC, pinned_at DESC, sort_order, id")->fetchAll();
} catch (Exception $e) {
    error_log("Error loading FAQs: " . $e->getMessage());
}

// 讀取場地（依區域分組）
$venues_by_region = [];
try {
    $venue_rows = $pdo->query("SELECT name, region, address FROM venues WHERE is_active = 1 ORDER BY region, name")->fetchAll();
    foreach ($venue_rows as $vr) {
        $venues_by_region[$vr['region']][] = $vr;
    }
} catch (Exception $e) {
    error_log("Error loading venues: " . $e->getMessage());
}
?>

<?php 
$hero_phone_formatted = formatPhone(s('hero_phone','0981881799')); 
$hero_bg = s('hero_bg_image_file') ? '/assets/images/' . s('hero_bg_image_file') : s('hero_bg_image','https://images.unsplash.com/photo-1622279457486-62dcc4a431d6?ixlib=rb-4.0.3&auto=format&fit=crop&w=2070&q=80');
?>
<section class="relative min-h-screen flex items-center overflow-hidden" x-data="{ loaded: false }" x-init="setTimeout(() => loaded = true, 100)">
    <div class="absolute inset-0">
        <img src="<?php echo htmlspecialchars($hero_bg); ?>" 
             class="w-full h-full object-cover scale-110" 
             style="transform: translateY(var(--scroll-y, 0))">
        <div class="absolute inset-0 bg-gradient-to-br from-slate-900 via-slate-900/95 to-slate-900/70"></div>
        <div class="absolute inset-0 bg-gradient-to-t from-slate-900 via-transparent to-transparent"></div>
    </div>
    
    <div class="absolute top-20 right-10 w-72 h-72 bg-green-500/10 rounded-full blur-3xl animate-pulse"></div>
    <div class="absolute bottom-20 left-10 w-96 h-96 bg-blue-500/5 rounded-full blur-3xl"></div>
    
    <div class="container mx-auto px-6 relative z-10">
        <div class="grid lg:grid-cols-2 gap-12 items-center">
            <div>
                <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-green-500/10 border border-green-500/30 text-green-400 mb-8"
                     :class="loaded ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-4'"
                     style="transition: all 0.6s ease 0.1s">
                    <span class="w-2 h-2 bg-green-400 rounded-full animate-pulse"></span>
                    <span class="text-sm font-medium tracking-wide"><?php echo htmlspecialchars(s('hero_badge','專業網球教學團隊')); ?></span>
                </div>
                
                <?php 
                $hero_logo = s('hero_logo_file') ? '/assets/images/' . s('hero_logo_file') : s('hero_logo');
                if ($hero_logo): 
                ?>
                <img src="<?php echo htmlspecialchars($hero_logo); ?>" 
                     alt="Logo" 
                     class="h-16 md:h-20 mb-6"
                     :class="loaded ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-4'"
                     style="transition: all 0.6s ease 0.2s">
                <?php endif; ?>
                
                <h1 class="text-4xl md:text-5xl lg:text-6xl font-black text-white leading-tight mb-6"
                    :class="loaded ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-4'"
                    style="transition: all 0.6s ease 0.3s">
                    <?php echo htmlspecialchars(s('hero_title_1','讓網球風行')); ?><br>
                    <span class="text-transparent bg-clip-text bg-gradient-to-r from-green-400 to-emerald-300"><?php echo htmlspecialchars(s('hero_title_2','專業教練 · 快樂學習')); ?></span>
                </h1>
                
                <p class="text-lg md:text-xl text-slate-300 mb-10 leading-relaxed max-w-xl"
                   :class="loaded ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-4'"
                   style="transition: all 0.6s ease 0.4s">
                    <?php echo htmlspecialchars(s('hero_desc')); ?>
                </p>
                
                <div class="flex flex-wrap gap-4"
                     :class="loaded ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-4'"
                     style="transition: all 0.6s ease 0.5s">
                    <?php foreach ($cta_buttons as $btn): 
                        $btn_style = $btn['button_style'] ?? 'primary';
                        $btn_classes = '';
                        if ($btn_style === 'primary') {
                            $btn_classes = 'group inline-flex items-center gap-3 px-8 py-4 bg-gradient-to-r from-green-500 to-emerald-600 hover:from-green-600 hover:to-emerald-700 text-white font-bold rounded-2xl transition-all hover:scale-105 shadow-lg shadow-green-500/25 hover:shadow-green-500/40';
                        } elseif ($btn_style === 'secondary') {
                            $btn_classes = 'inline-flex items-center gap-3 px-8 py-4 bg-white/5 hover:bg-white/10 text-white font-bold rounded-2xl transition-all border border-white/10 hover:border-white/20 backdrop-blur-sm';
                        } else {
                            $btn_classes = 'inline-flex items-center gap-3 px-8 py-4 bg-transparent hover:bg-white/5 text-white font-bold rounded-2xl transition-all border-2 border-white/30 hover:border-white/50';
                        }
                    ?>
                    <a href="<?php echo htmlspecialchars($btn['button_url'] ?? '#'); ?>" 
                       target="_blank" 
                       rel="noopener noreferrer"
                       class="<?php echo $btn_classes; ?>">
                        <i class="fas <?php echo htmlspecialchars($btn['button_icon'] ?? 'fa-arrow-right'); ?>"></i>
                        <?php echo htmlspecialchars($btn['button_text'] ?? ''); ?>
                        <?php if ($btn_style === 'primary'): ?>
                        <i class="fas fa-arrow-right opacity-0 -translate-x-2 group-hover:opacity-100 group-hover:translate-x-0 transition-all"></i>
                        <?php endif; ?>
                    </a>
                    <?php endforeach; ?>
                </div>
                
                <div class="mt-10 flex items-center gap-6"
                     :class="loaded ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-4'"
                     style="transition: all 0.6s ease 0.6s">
                    <a href="tel:<?php echo htmlspecialchars(s('hero_phone')); ?>" class="flex items-center gap-3 text-slate-400 hover:text-green-400 transition-colors">
                        <div class="w-10 h-10 rounded-full bg-white/5 flex items-center justify-center">
                            <i class="fas fa-phone text-sm"></i>
                        </div>
                        <span class="font-medium"><?php echo $hero_phone_formatted; ?></span>
                    </a>
                    <a href="mailto:<?php echo htmlspecialchars(s('hero_email')); ?>" class="flex items-center gap-3 text-slate-400 hover:text-green-400 transition-colors">
                        <div class="w-10 h-10 rounded-full bg-white/5 flex items-center justify-center">
                            <i class="fas fa-envelope text-sm"></i>
                        </div>
                        <span class="font-medium hidden md:inline">Email 諮詢</span>
                    </a>
                </div>
            </div>
            
            <!-- 9 宮格系統 -->
            <div class="hidden lg:block"
                 :class="loaded ? 'opacity-100 translate-x-0' : 'opacity-0 translate-x-8'"
                 style="transition: all 0.8s ease 0.4s">
                <div class="grid grid-cols-3 gap-3 max-w-md">
                    <?php
                    // 準備 9 宮格內容
                    $grid_content = array_fill(0, 9, null);
                    
                    // 上 3 格：置頂公告
                    $colors = ['blue', 'emerald', 'purple', 'amber', 'pink', 'orange'];
                    foreach ($pinned_announcements as $idx => $ann) {
                        if ($idx < 3) {
                            $color = $colors[$idx % count($colors)];
                            $grid_content[$idx] = [
                                'type' => 'announcement',
                                'title' => $ann['title'],
                                'desc' => mb_substr($ann['content'], 0, 30) . '...',
                                'icon' => 'fa-bullhorn',
                                'color' => $color
                            ];
                        }
                    }
                    
                    // 中 2 格：自訂項目（位置 4-5，即第二排中間兩格）
                    foreach ($grid_items as $item) {
                        $pos = intval($item['grid_position']);
                        if ($pos >= 0 && $pos < 9 && $grid_content[$pos] === null) {
                            $grid_content[$pos] = [
                                'type' => 'custom',
                                'title' => $item['title'],
                                'desc' => $item['description'] ?? '',
                                'icon' => $item['icon'] ?? 'fa-star',
                                'color' => $item['color'] ?? 'blue',
                                'link' => $item['link_url'] ?? '#'
                            ];
                        }
                    }
                    
                    // 下 4 格：統計數據（位置 6-9）
                    $stats = [
                        ['value' => s('stat_1_value', '10'), 'suffix' => s('stat_1_suffix', '+'), 'label' => s('stat_1_label', '年經驗'), 'icon' => s('stat_1_icon', 'fa-clock'), 'color' => s('stat_1_color', 'green')],
                        ['value' => s('stat_2_value', '500'), 'suffix' => s('stat_2_suffix', '+'), 'label' => s('stat_2_label', '學員'), 'icon' => s('stat_2_icon', 'fa-users'), 'color' => s('stat_2_color', 'blue')],
                        ['value' => s('stat_3_value', '5000'), 'suffix' => s('stat_3_suffix', '+'), 'label' => s('stat_3_label', '課程'), 'icon' => s('stat_3_icon', 'fa-graduation-cap'), 'color' => s('stat_3_color', 'purple')],
                        ['value' => s('stat_4_value', '98'), 'suffix' => s('stat_4_suffix', '%'), 'label' => s('stat_4_label', '滿意度'), 'icon' => s('stat_4_icon', 'fa-star'), 'color' => s('stat_4_color', 'amber')]
                    ];
                    
                    for ($i = 0; $i < 4; $i++) {
                        $pos = 6 + $i;
                        if (!isset($grid_content[$pos]) || $grid_content[$pos] === null) {
                            $grid_content[$pos] = [
                                'type' => 'stat',
                                'value' => $stats[$i]['value'],
                                'suffix' => $stats[$i]['suffix'],
                                'label' => $stats[$i]['label'],
                                'icon' => $stats[$i]['icon'],
                                'color' => $stats[$i]['color']
                            ];
                        }
                    }
                    
                    // 統一格式：將 stat 類型轉換為統一的 title/desc 格式
                    foreach ($grid_content as $idx => &$item) {
                        if ($item === null) continue;
                        if ($item['type'] === 'stat') {
                            $item['title'] = $item['value'] . $item['suffix'];
                            $item['desc'] = $item['label'];
                            $item['icon'] = $item['icon'] ?? 'fa-star';
                        }
                    }
                    unset($item);
                    
                    // 渲染 9 宮格（統一版型）
                    foreach ($grid_content as $idx => $item):
                        if ($item === null) continue;
                        $color = $item['color'] ?? 'slate';
                        $link = $item['link'] ?? '';
                        $tag = $link ? 'a' : 'div';
                    ?>
                    <div class="aspect-square bg-slate-800/60 backdrop-blur-sm border border-white/10 rounded-xl p-3 hover:border-<?php echo $color; ?>-500/30 hover:bg-slate-800/80 transition-all group">
                        <<?php echo $tag; ?><?php echo $link ? ' href="' . htmlspecialchars($link) . '"' : ''; ?> class="h-full flex flex-col">
                            <div class="w-8 h-8 rounded-lg bg-<?php echo $color; ?>-500/20 flex items-center justify-center text-<?php echo $color; ?>-400 mb-2 group-hover:scale-110 transition-transform">
                                <i class="fas <?php echo $item['icon']; ?> text-sm"></i>
                            </div>
                            <h4 class="text-white text-xs font-bold mb-1 line-clamp-2"><?php echo htmlspecialchars($item['title']); ?></h4>
                            <p class="text-slate-400 text-[10px] line-clamp-2"><?php echo htmlspecialchars($item['desc'] ?? ''); ?></p>
                        </<?php echo $tag; ?>>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    
    <div class="absolute bottom-8 left-1/2 -translate-x-1/2 flex flex-col items-center gap-2 text-white/40">
        <span class="text-xs uppercase tracking-widest">向下滾動</span>
        <i class="fas fa-chevron-down animate-bounce"></i>
    </div>
</section>

<?php if (!empty($announcements)): ?>
<?php
// 準備公告 JSON 供 Alpine.js 使用（含格數計算，用於分頁）
$ann_json_items = [];
foreach ($announcements as $ann) {
    $at = $ann_type_map[$ann['type']] ?? null;
    $layout = $ann['layout'] ?? 'default';
    $cells = ($layout === 'banner') ? 3 : (($layout === 'wide') ? 2 : 1);
    $ann_json_items[] = [
        'title'   => $ann['title'],
        'content' => $ann['content'],
        'banner'  => $ann['banner_image'] ?? '',
        'type'    => $at['name'] ?? $ann['type'],
        'icon'    => $at['icon'] ?? 'fa-info-circle',
        'color'   => $at['color'] ?? 'blue',
        'date'    => date('Y/m/d', strtotime($ann['created_at'])),
        'layout'  => $layout,
        'cells'   => $cells,
    ];
}
?>
<section class="py-20 bg-slate-900/80 border-y border-white/5">
    <div class="container mx-auto px-6" x-data="annPager(<?php echo htmlspecialchars(json_encode($ann_json_items, JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>)">
        <div class="text-center mb-12">
            <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-amber-500/10 border border-amber-500/20 text-amber-400 text-sm font-medium mb-6">
                <i class="fas fa-bullhorn"></i> 最新公告
            </div>
            <h2 class="text-3xl md:text-4xl font-black text-white">公告資訊</h2>
            <p class="text-slate-500 text-sm mt-3">點擊公告查看詳細內容</p>
        </div>

        <!-- 九宮格公告（支援 default/wide/banner 三種尺寸）含分頁 -->
        <div x-show="selected === null" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-4" x-transition:enter-end="opacity-100 translate-y-0">
            <div class="grid grid-cols-3 gap-3 md:gap-4 max-w-4xl mx-auto">
                <template x-for="(ann, i) in currentPage()" :key="page + '-' + i">
                    <div @click="selectAnn(ann.origIndex)" 
                         class="group border rounded-xl md:rounded-2xl overflow-hidden cursor-pointer hover:scale-[1.03] hover:shadow-lg hover:shadow-black/20 transition-all"
                         :class="[
                             ann.color === 'green'  ? 'bg-gradient-to-b from-emerald-500/20 to-emerald-500/5 border-emerald-500/20 text-emerald-400' :
                             ann.color === 'amber'  ? 'bg-gradient-to-b from-amber-500/20 to-amber-500/5 border-amber-500/20 text-amber-400' :
                             ann.color === 'red'    ? 'bg-gradient-to-b from-red-500/20 to-red-500/5 border-red-500/20 text-red-400' :
                             ann.color === 'purple' ? 'bg-gradient-to-b from-purple-500/20 to-purple-500/5 border-purple-500/20 text-purple-400' :
                                                      'bg-gradient-to-b from-blue-500/20 to-blue-500/5 border-blue-500/20 text-blue-400',
                             ann.layout === 'wide' ? 'col-span-2' : (ann.layout === 'banner' ? 'col-span-3' : '')
                         ]">
                        <div x-show="ann.banner" class="aspect-[3/1] overflow-hidden">
                            <img :src="'/assets/images/' + ann.banner" class="w-full h-full object-cover group-hover:scale-110 transition-transform duration-500" alt="">
                        </div>
                        <div class="p-3 md:p-4" :class="!ann.banner ? 'py-4 md:py-5' : ''">
                            <div class="flex items-center gap-1.5 mb-1">
                                <i class="fas text-[10px] md:text-xs" :class="ann.icon"></i>
                                <span class="text-[10px] md:text-[11px] font-bold uppercase" x-text="ann.type"></span>
                                <span class="ml-auto text-slate-600 text-[9px] md:text-[10px]" x-text="ann.date"></span>
                            </div>
                            <h3 class="text-white font-bold text-xs md:text-sm leading-snug line-clamp-2" x-text="ann.title"></h3>
                        </div>
                    </div>
                </template>
            </div>

            <!-- 分頁控制 -->
            <div x-show="totalPages() > 1" class="flex items-center justify-center gap-3 mt-8">
                <button @click="prevPage()" :disabled="page === 0" 
                        class="w-10 h-10 rounded-xl flex items-center justify-center transition-all"
                        :class="page === 0 ? 'bg-slate-800/30 text-slate-600 cursor-default' : 'bg-slate-800 text-slate-300 hover:bg-slate-700 hover:text-white'">
                    <i class="fas fa-chevron-left text-sm"></i>
                </button>
                <div class="flex items-center gap-1.5">
                    <template x-for="p in totalPages()" :key="p">
                        <button @click="page = p - 1" 
                                class="w-2.5 h-2.5 rounded-full transition-all"
                                :class="page === p - 1 ? 'bg-amber-400 scale-125' : 'bg-slate-600 hover:bg-slate-500'">
                        </button>
                    </template>
                </div>
                <button @click="nextPage()" :disabled="page >= totalPages() - 1" 
                        class="w-10 h-10 rounded-xl flex items-center justify-center transition-all"
                        :class="page >= totalPages() - 1 ? 'bg-slate-800/30 text-slate-600 cursor-default' : 'bg-slate-800 text-slate-300 hover:bg-slate-700 hover:text-white'">
                    <i class="fas fa-chevron-right text-sm"></i>
                </button>
                <span class="text-slate-600 text-xs ml-2" x-text="(page+1) + ' / ' + totalPages()"></span>
            </div>
        </div>

        <!-- 詳細資訊面板 -->
        <div x-show="selected !== null" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-4" x-transition:enter-end="opacity-100 translate-y-0"
             class="max-w-3xl mx-auto">
            <template x-if="selected !== null">
                <div>
                    <!-- 返回按鈕 -->
                    <button @click="selected = null" class="group flex items-center gap-2 text-sm text-slate-400 hover:text-white mb-6 transition-colors">
                        <i class="fas fa-arrow-left group-hover:-translate-x-1 transition-transform"></i>
                        <span>返回公告列表</span>
                    </button>

                    <!-- 橫幅圖片 -->
                    <div x-show="allItems[selected].banner" class="rounded-2xl overflow-hidden mb-6 border border-white/10">
                        <img :src="'/assets/images/' + allItems[selected].banner" class="w-full" style="aspect-ratio:2/1; object-fit:cover;" alt="">
                    </div>

                    <!-- 內容卡片 -->
                    <div class="rounded-2xl border border-white/10 overflow-hidden"
                         :class="allItems[selected].color === 'green'  ? 'bg-gradient-to-b from-emerald-500/10 to-transparent border-emerald-500/20' :
                                 allItems[selected].color === 'amber'  ? 'bg-gradient-to-b from-amber-500/10 to-transparent border-amber-500/20' :
                                 allItems[selected].color === 'red'    ? 'bg-gradient-to-b from-red-500/10 to-transparent border-red-500/20' :
                                 allItems[selected].color === 'purple' ? 'bg-gradient-to-b from-purple-500/10 to-transparent border-purple-500/20' :
                                                                      'bg-gradient-to-b from-blue-500/10 to-transparent border-blue-500/20'">
                        <div class="p-6 md:p-8">
                            <!-- 類型 & 日期 -->
                            <div class="flex items-center gap-3 mb-4">
                                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold"
                                      :class="allItems[selected].color === 'green'  ? 'bg-emerald-500/20 text-emerald-400' :
                                              allItems[selected].color === 'amber'  ? 'bg-amber-500/20 text-amber-400' :
                                              allItems[selected].color === 'red'    ? 'bg-red-500/20 text-red-400' :
                                              allItems[selected].color === 'purple' ? 'bg-purple-500/20 text-purple-400' :
                                                                                   'bg-blue-500/20 text-blue-400'">
                                    <i class="fas" :class="allItems[selected].icon"></i>
                                    <span x-text="allItems[selected].type"></span>
                                </span>
                                <span class="text-slate-500 text-sm"><i class="fas fa-clock mr-1"></i><span x-text="allItems[selected].date"></span></span>
                            </div>
                            <!-- 標題 -->
                            <h2 class="text-2xl md:text-3xl font-black text-white mb-6 leading-tight" x-text="allItems[selected].title"></h2>
                            <!-- 內文 -->
                            <div class="text-slate-300 leading-relaxed text-sm md:text-base whitespace-pre-line" x-text="allItems[selected].content"></div>
                        </div>
                    </div>

                    <!-- 上一則 / 下一則導航 -->
                    <div class="flex items-center justify-between mt-6 gap-4">
                        <button @click="selected = selected > 0 ? selected - 1 : null"
                                class="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-medium transition-all"
                                :class="selected > 0 ? 'bg-slate-800 text-slate-300 hover:bg-slate-700 hover:text-white' : 'bg-slate-800/30 text-slate-600 cursor-default'">
                            <i class="fas fa-chevron-left text-xs"></i>
                            <span x-text="selected > 0 ? '上一則' : '回到列表'"></span>
                        </button>
                        <span class="text-slate-600 text-xs" x-text="(selected+1) + ' / ' + allItems.length"></span>
                        <button @click="selected = selected < allItems.length - 1 ? selected + 1 : null"
                                class="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-medium transition-all"
                                :class="selected < allItems.length - 1 ? 'bg-slate-800 text-slate-300 hover:bg-slate-700 hover:text-white' : 'bg-slate-800/30 text-slate-600 cursor-default'">
                            <span x-text="selected < allItems.length - 1 ? '下一則' : '回到列表'"></span>
                            <i class="fas fa-chevron-right text-xs"></i>
                        </button>
                    </div>
                </div>
            </template>
        </div>
    </div>

    <script>
    function annPager(items) {
        return {
            allItems: items,
            page: 0,
            selected: null,
            maxCells: 9,

            // 依格數分頁（default=1, wide=2, banner=3），每頁最多 9 格
            getPages() {
                let pages = [];
                let current = [];
                let cells = 0;
                for (let i = 0; i < this.allItems.length; i++) {
                    let c = this.allItems[i].cells || 1;
                    if (cells + c > this.maxCells && current.length > 0) {
                        pages.push(current);
                        current = [];
                        cells = 0;
                    }
                    current.push({ ...this.allItems[i], origIndex: i });
                    cells += c;
                }
                if (current.length > 0) pages.push(current);
                return pages;
            },

            currentPage() {
                let pages = this.getPages();
                return pages[this.page] || [];
            },

            totalPages() {
                return this.getPages().length;
            },

            prevPage() {
                if (this.page > 0) this.page--;
            },

            nextPage() {
                if (this.page < this.totalPages() - 1) this.page++;
            },

            selectAnn(origIndex) {
                this.selected = origIndex;
                this.$nextTick(() => {
                    this.$el.closest('section').scrollIntoView({ behavior: 'smooth', block: 'start' });
                });
            }
        };
    }
    </script>
</section>
<?php endif; ?>

<section class="py-28 relative">
    <div class="absolute top-0 left-0 w-full h-1 bg-gradient-to-r from-transparent via-green-500/20 to-transparent"></div>
    
    <div class="container mx-auto px-6">
        <div class="text-center mb-20">
            <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-green-500/10 border border-green-500/20 text-green-400 text-sm font-medium mb-6">
                <i class="fas fa-tennis-ball"></i> <?php echo htmlspecialchars(s('services_badge','課程服務')); ?>
            </div>
            <h2 class="text-3xl md:text-5xl font-black text-white mb-6"><?php echo htmlspecialchars(s('services_title','我們的服務')); ?></h2>
            <p class="text-slate-400 text-lg max-w-2xl mx-auto"><?php echo htmlspecialchars(s('services_subtitle')); ?></p>
        </div>
        
        <div class="grid md:grid-cols-3 gap-8">
            <?php 
            $color_map = [
                'green' => ['border' => 'hover:border-green-500/30', 'shadow' => 'hover:shadow-green-500/10', 'bg' => 'from-green-500/20 to-green-500/5', 'text' => 'text-green-400', 'glow' => 'from-green-500/5'],
                'blue' => ['border' => 'hover:border-blue-500/30', 'shadow' => 'hover:shadow-blue-500/10', 'bg' => 'from-blue-500/20 to-blue-500/5', 'text' => 'text-blue-400', 'glow' => 'from-blue-500/5'],
                'purple' => ['border' => 'hover:border-purple-500/30', 'shadow' => 'hover:shadow-purple-500/10', 'bg' => 'from-purple-500/20 to-purple-500/5', 'text' => 'text-purple-400', 'glow' => 'from-purple-500/5'],
                'orange' => ['border' => 'hover:border-orange-500/30', 'shadow' => 'hover:shadow-orange-500/10', 'bg' => 'from-orange-500/20 to-orange-500/5', 'text' => 'text-orange-400', 'glow' => 'from-orange-500/5'],
                'pink' => ['border' => 'hover:border-pink-500/30', 'shadow' => 'hover:shadow-pink-500/10', 'bg' => 'from-pink-500/20 to-pink-500/5', 'text' => 'text-pink-400', 'glow' => 'from-pink-500/5'],
            ];
            foreach ($homepage_blocks as $block): 
                $c = $color_map[$block['color']] ?? $color_map['green'];
            ?>
            <div class="group relative p-8 rounded-3xl bg-gradient-to-b from-slate-800/80 to-slate-800/40 border border-white/5 <?php echo $c['border']; ?> transition-all duration-500 hover:-translate-y-3 hover:shadow-2xl <?php echo $c['shadow']; ?>">
                <div class="absolute top-0 left-0 w-full h-full rounded-3xl bg-gradient-to-b <?php echo $c['glow']; ?> to-transparent opacity-0 group-hover:opacity-100 transition-opacity"></div>
                <div class="relative z-10">
                    <div class="w-16 h-16 rounded-2xl bg-gradient-to-br <?php echo $c['bg']; ?> flex items-center justify-center <?php echo $c['text']; ?> mb-6 group-hover:scale-110 group-hover:rotate-3 transition-all">
                        <i class="fas <?php echo htmlspecialchars($block['icon']); ?> text-2xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-white mb-3"><?php echo htmlspecialchars($block['title']); ?></h3>
                    <p class="text-slate-400 leading-relaxed <?php echo $block['link_url'] ? 'mb-6' : 'mb-0'; ?>"><?php echo htmlspecialchars($block['description']); ?></p>
                    <?php if (!empty($block['link_url']) && !empty($block['link_text'])): ?>
                    <a href="<?php echo htmlspecialchars($block['link_url']); ?>" class="inline-flex items-center gap-2 <?php echo $c['text']; ?> text-sm font-medium opacity-0 group-hover:opacity-100 transition-opacity">
                        <?php echo htmlspecialchars($block['link_text']); ?> <i class="fas fa-arrow-right"></i>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="py-28 bg-slate-800/30 relative overflow-hidden">
    <div class="absolute inset-0 opacity-5">
        <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[800px] h-[800px] border border-white/20 rounded-full"></div>
        <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[600px] h-[600px] border border-white/20 rounded-full"></div>
        <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[400px] h-[400px] border border-white/20 rounded-full"></div>
    </div>
    
    <div class="container mx-auto px-6 relative z-10">
        <div class="text-center mb-20">
            <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-purple-500/10 border border-purple-500/20 text-purple-400 text-sm font-medium mb-6">
                <i class="fas fa-map-marked-alt"></i> <?php echo htmlspecialchars(s('venues_badge','服務範圍')); ?>
            </div>
            <h2 class="text-3xl md:text-5xl font-black text-white mb-6"><?php echo htmlspecialchars(s('venues_title','服務據點')); ?></h2>
            <p class="text-slate-400 text-lg"><?php echo htmlspecialchars(s('venues_subtitle','從北到南，讓您就近學習')); ?></p>
        </div>
        
        <?php
        $region_meta = [
            'north'   => ['label'=>'北部','color'=>'blue',  'icon'=>'fa-city'],
            'central' => ['label'=>'中部','color'=>'green', 'icon'=>'fa-tree'],
            'south'   => ['label'=>'南部','color'=>'purple','icon'=>'fa-sun'],
            'east'    => ['label'=>'東部','color'=>'orange', 'icon'=>'fa-mountain'],
        ];
        $region_count = count($venues_by_region);
        ?>
        <div class="grid md:grid-cols-<?php echo min($region_count, 3); ?> gap-8 max-w-5xl mx-auto" x-data="venueModal()">
            <?php foreach ($region_meta as $rk => $rm):
                if (empty($venues_by_region[$rk])) continue;
                $rc = $rm['color'];
                $venue_count = count($venues_by_region[$rk]);
            ?>
            <div class="group relative p-8 rounded-3xl bg-gradient-to-b from-slate-800/80 to-slate-800/40 border border-white/5 hover:border-<?php echo $rc; ?>-500/30 transition-all duration-500 hover:-translate-y-2">
                <div class="absolute top-4 right-4 px-3 py-1 rounded-full bg-<?php echo $rc; ?>-500/10 text-<?php echo $rc; ?>-400 text-xs font-medium">
                    <?php echo $rm['label']; ?>
                </div>
                <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-<?php echo $rc; ?>-500/20 to-<?php echo $rc; ?>-500/5 text-<?php echo $rc; ?>-400 flex items-center justify-center mb-6 group-hover:scale-110 transition-transform">
                    <i class="fas <?php echo $rm['icon']; ?> text-2xl"></i>
                </div>
                <div class="space-y-3">
                    <?php 
                    $display_limit = 3;
                    $venues_to_show = array_slice($venues_by_region[$rk], 0, $display_limit);
                    foreach ($venues_to_show as $venue): 
                    ?>
                    <div>
                        <div class="flex items-center gap-2 text-white font-medium">
                            <i class="fas fa-check text-<?php echo $rc; ?>-400 text-xs"></i>
                            <?php echo htmlspecialchars($venue['name']); ?>
                        </div>
                        <?php if ($venue['address']): ?>
                        <div class="text-slate-500 text-xs ml-5"><?php echo htmlspecialchars($venue['address']); ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    
                    <?php if ($venue_count > $display_limit): ?>
                    <button @click="openModal('<?php echo $rk; ?>', '<?php echo $rm['label']; ?>', '<?php echo $rc; ?>')" 
                            class="mt-4 w-full py-2 px-4 rounded-lg bg-<?php echo $rc; ?>-500/10 hover:bg-<?php echo $rc; ?>-500/20 text-<?php echo $rc; ?>-400 text-sm font-medium transition-all">
                        <i class="fas fa-plus-circle mr-2"></i>查看更多 (<?php echo $venue_count - $display_limit; ?>)
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            
            <!-- 懸浮視窗 Modal -->
            <div x-show="showModal" 
                 x-cloak
                 @click.self="closeModal()"
                 class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm">
                <div class="bg-slate-900 rounded-2xl max-w-2xl w-full max-h-[80vh] overflow-hidden border border-white/10 shadow-2xl"
                     @click.stop>
                    <!-- Header -->
                    <div class="flex items-center justify-between p-6 border-b border-white/10">
                        <div class="flex items-center gap-3">
                            <div class="w-12 h-12 rounded-xl flex items-center justify-center"
                                 :class="'bg-' + currentColor + '-500/20 text-' + currentColor + '-400'">
                                <i class="fas fa-map-marker-alt text-xl"></i>
                            </div>
                            <div>
                                <h3 class="text-xl font-bold text-white" x-text="currentRegion + ' 服務據點'"></h3>
                                <p class="text-sm text-slate-400" x-text="'共 ' + totalVenues + ' 個據點'"></p>
                            </div>
                        </div>
                        <button @click="closeModal()" 
                                class="w-10 h-10 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-400 hover:text-white transition-all">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    
                    <!-- Content -->
                    <div class="p-6 overflow-y-auto max-h-[50vh]">
                        <div class="space-y-4">
                            <template x-for="venue in currentPageVenues" :key="venue.name">
                                <div class="p-4 rounded-xl bg-slate-800/50 border border-white/5 hover:border-white/10 transition-all">
                                    <div class="flex items-start gap-3">
                                        <div class="w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0"
                                             :class="'bg-' + currentColor + '-500/20 text-' + currentColor + '-400'">
                                            <i class="fas fa-map-pin"></i>
                                        </div>
                                        <div class="flex-1">
                                            <div class="text-white font-bold mb-1" x-text="venue.name"></div>
                                            <div class="text-slate-400 text-sm" x-text="venue.address"></div>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                    
                    <!-- Pagination -->
                    <div class="p-6 border-t border-white/10 bg-slate-800/30">
                        <div class="flex items-center justify-between">
                            <div class="text-sm text-slate-400">
                                第 <span x-text="currentPage"></span> 頁，共 <span x-text="totalPages"></span> 頁
                            </div>
                            <div class="flex gap-2">
                                <button @click="prevPage()" 
                                        :disabled="currentPage === 1"
                                        :class="currentPage === 1 ? 'opacity-50 cursor-not-allowed' : 'hover:bg-slate-700'"
                                        class="px-4 py-2 rounded-lg bg-slate-800 text-white transition-all">
                                    <i class="fas fa-chevron-left"></i>
                                </button>
                                <button @click="nextPage()" 
                                        :disabled="currentPage === totalPages"
                                        :class="currentPage === totalPages ? 'opacity-50 cursor-not-allowed' : 'hover:bg-slate-700'"
                                        class="px-4 py-2 rounded-lg bg-slate-800 text-white transition-all">
                                    <i class="fas fa-chevron-right"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="text-center mt-12">
            <p class="text-slate-500 text-sm">
                <i class="fas fa-info-circle mr-2"></i>
                <?php echo htmlspecialchars(s('venues_footer','其他地區如有需求，歡迎來電洽詢，我們將盡力安排')); ?>
            </p>
        </div>
        
        <script>
        function venueModal() {
            return {
                showModal: false,
                currentRegion: '',
                currentColor: 'blue',
                currentPage: 1,
                perPage: 5,
                allVenues: [],
                venuesData: <?php echo json_encode($venues_by_region); ?>,
                
                get totalVenues() {
                    return this.allVenues.length;
                },
                
                get totalPages() {
                    return Math.ceil(this.totalVenues / this.perPage);
                },
                
                get currentPageVenues() {
                    const start = (this.currentPage - 1) * this.perPage;
                    const end = start + this.perPage;
                    return this.allVenues.slice(start, end);
                },
                
                openModal(region, regionLabel, color) {
                    this.currentRegion = regionLabel;
                    this.currentColor = color;
                    this.allVenues = this.venuesData[region] || [];
                    this.currentPage = 1;
                    this.showModal = true;
                    document.body.style.overflow = 'hidden';
                },
                
                closeModal() {
                    this.showModal = false;
                    document.body.style.overflow = '';
                },
                
                nextPage() {
                    if (this.currentPage < this.totalPages) {
                        this.currentPage++;
                    }
                },
                
                prevPage() {
                    if (this.currentPage > 1) {
                        this.currentPage--;
                    }
                }
            }
        }
        </script>
    </div>
</section>

<!-- 底部資訊整合區：常見問題 + 聯絡我們 -->
<?php $ct_phone_fmt = formatPhone(s('contact_phone','0981881799')); ?>
<section class="py-28 bg-slate-800/30">
    <div class="container mx-auto px-6">
        <div class="max-w-6xl mx-auto">
            
            <!-- 常見問題 -->
            <?php if (!empty($faqs)): ?>
            <div class="mb-20">
                <div class="text-center mb-12">
                    <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-amber-500/10 border border-amber-500/20 text-amber-400 text-sm font-medium mb-6">
                        <i class="fas fa-question-circle"></i> FAQ
                    </div>
                    <h2 class="text-3xl md:text-4xl font-black text-white mb-4">常見問題</h2>
                    <p class="text-slate-400">有問題嗎？看看這裡能不能幫到你</p>
                </div>
                <div class="max-w-3xl mx-auto space-y-3" x-data="{ openFaq: null }">
                    <?php foreach ($faqs as $i => $faq): ?>
                    <div class="rounded-xl border border-white/5 overflow-hidden bg-slate-800/50 hover:border-white/10 transition-all">
                        <button @click="openFaq = openFaq === <?php echo $i; ?> ? null : <?php echo $i; ?>" class="w-full flex items-center justify-between p-5 text-left">
                            <span class="text-white font-bold pr-4 text-sm"><?php echo htmlspecialchars($faq['question']); ?></span>
                            <i class="fas fa-chevron-down text-slate-500 transition-transform text-xs" :class="openFaq === <?php echo $i; ?> ? 'rotate-180 text-amber-400' : ''"></i>
                        </button>
                        <div x-show="openFaq === <?php echo $i; ?>" x-transition x-cloak class="px-5 pb-5 text-slate-400 text-sm leading-relaxed">
                            <?php echo nl2br(htmlspecialchars($faq['answer'])); ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php require_once 'includes/footer.php'; ?>
