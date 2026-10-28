<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>商品展示</title>
    <link rel="stylesheet" href="CSS/style3.css" />
</head>
<body>
    <div class="main">
        <div class="main-nav-position">
            <div class="main-nav">
                <div class="main-nav-logo half-nav"></div>
                <div class="main-nav-seach">
                    <form method="get" action="" class="search-form">
                        <input type="text" name="search" placeholder="搜索商品..." 
                               value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>" 
                               class="search-input">
                        <button type="submit" class="search-button">搜索</button>
                    </form>
                </div>
                <div class="main-nav-login-regsiter half-nav">
                    <div class="nav-login"></div>
                    <div class="nav-regsiter"></div>
                </div>
            </div>
        </div>
        <div class="main-top-position">
            <div class="main-top">
                <div class="main-top-left maintop">
                    <div class="top-float" id="top-float-1"></div>
                </div>
                <div class="main-top-right maintop">
                    <div class="top-float" id="top-float-2"></div>
                </div>
            </div>
        </div>
        
        <div class="main-botton-position">
            <div class="main-botton">
                <?php
                    require_once 'db_connect.php';
                    // 常量定义：每页显示商品数量
                    define('PER_PAGE', 12);

                    // 获取当前页码，默认为1，并确保不小于1
                    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
                    $page = max(1, $page);

                    // 获取分类筛选条件，默认为0（全部）
                    $category_id = isset($_GET['category']) ? (int)$_GET['category'] : 0;

                    // 获取搜索关键词，并去除首尾空格
                    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
                    
                    // 获取价格范围筛选条件
                    $min_price = isset($_GET['min_price']) ? (float)$_GET['min_price'] : null;
                    $max_price = isset($_GET['max_price']) ? (float)$_GET['max_price'] : null;
                    
                    // 获取商品状态筛选条件
                    $status = isset($_GET['status']) ? $_GET['status'] : '';

                    /**
                     * 获取商品数据函数
                     * @param PDO $conn 数据库连接
                     * @param int $page 当前页码
                     * @param int $perPage 每页数量
                     * @param int $category_id 分类ID
                     * @param string $search 搜索关键词
                     * @param float|null $min_price 最低价格
                     * @param float|null $max_price 最高价格
                     * @param string $status 商品状态
                     * @return array 包含商品数据和总数的数组
                     */
                    function getProducts($conn, $page, $perPage, $category_id = 0, $search = '', $min_price = null, $max_price = null, $status = '') {
                        $offset = ($page - 1) * $perPage;  // 计算偏移量
                        
                        // 构建查询条件和参数数组
                        $where = [];
                        $params = [];
                        
                        // 如果有分类筛选条件
                        if ($category_id > 0) {
                            $where[] = "p.category_id = :category_id";
                            $params[':category_id'] = $category_id;
                        }
                        
                        // 如果有搜索关键词
                        if (!empty($search)) {
                            $where[] = "(p.name LIKE :search OR p.description LIKE :search)";
                            $params[':search'] = "%{$search}%";
                        }
                        
                        // 添加价格范围筛选
                        if ($min_price !== null && $min_price > 0) {
                            $where[] = "p.price >= :min_price";
                            $params[':min_price'] = $min_price;
                        }
                        
                        if ($max_price !== null && $max_price > 0) {
                            $where[] = "p.price <= :max_price";
                            $params[':max_price'] = $max_price;
                        }
                        
                        // 添加商品状态筛选
                        if (!empty($status)) {
                            $where[] = "p.status = :status";
                            $params[':status'] = $status;
                        }
                        
                        // 组合WHERE子句
                        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
                        
                        // 查询商品总数
                        $countQuery = "SELECT COUNT(*) as total FROM products p $whereClause";
                        $stmt = $conn->prepare($countQuery);
                        $stmt->execute($params);
                        $total = $stmt->fetchColumn();
                        
                        // 查询商品数据
                        $query = "SELECT p.*, c.name as category_name 
                                  FROM products p 
                                  LEFT JOIN categories c ON p.category_id = c.id 
                                  $whereClause 
                                  ORDER BY p.created_at DESC 
                                  LIMIT :offset, :perPage";
                        
                        // 添加分页参数
                        $params[':offset'] = $offset;
                        $params[':perPage'] = $perPage;
                        
                        $stmt = $conn->prepare($query);
                        
                        // 绑定参数
                        foreach ($params as $key => $value) {
                            $paramType = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
                            $stmt->bindValue($key, $value, $paramType);
                        }
                        
                        $stmt->execute();
                        return ['products' => $stmt->fetchAll(), 'total' => $total];
                    }

                    /**
                     * 获取所有分类数据
                     * @param PDO $conn 数据库连接
                     * @return array 分类数组
                     */
                    function getCategories($conn) {
                        $stmt = $conn->query("SELECT * FROM categories ORDER BY name");
                        return $stmt->fetchAll();
                    }

                    // 获取商品数据
                    $data = getProducts($conn, $page, PER_PAGE, $category_id, $search, $min_price, $max_price, $status);
                    $products = $data['products'];
                    $total = $data['total'];
                    
                    // 获取分类数据
                    $categories = getCategories($conn);
                    
                    // 计算总页数
                    $totalPages = ceil($total / PER_PAGE);
                ?>
                    <div class="product-display-container">
                        <!-- 搜索和筛选表单 -->
                        <div class="search-filter">
                            <form method="get" action="">
                                <!-- 分类下拉选择 -->
                                <select name="category">
                                    <option value="0">所有分类</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?php echo $category['id']; ?>" <?php echo $category_id == $category['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($category['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                
                                <!-- 价格范围筛选 -->
                                <div class="price-filter">
                                    <label>PRICE</label>
                                    <input type="number" name="min_price" placeholder="MIN" 
                                           value="<?php echo isset($_GET['min_price']) ? htmlspecialchars($_GET['min_price']) : ''; ?>">
                                    <span> - </span>
                                    <input type="number" name="max_price" placeholder="MAX" 
                                           value="<?php echo isset($_GET['max_price']) ? htmlspecialchars($_GET['max_price']) : ''; ?>">
                                </div>
                                
                                <!-- 商品状态筛选 -->
                                <div class="status-filter">
                                    <label>STATUS</label>
                                    <select name="status">
                                        <option value="">ALL</option>
                                        <option value="全新" <?php echo isset($_GET['status']) && $_GET['status'] == '全新' ? 'selected' : ''; ?>>NEW</option>
                                        <option value="二手" <?php echo isset($_GET['status']) && $_GET['status'] == '二手' ? 'selected' : ''; ?>>USED</option>
                                        <option value="损坏" <?php echo isset($_GET['status']) && $_GET['status'] == '损坏' ? 'selected' : ''; ?>>BROKE</option>
                                    </select>
                                </div>
                                
                                <button type="submit">FILTER</button>
                                <a href="?" style="margin-left: 10px;">RESET</a>  <!-- 重置筛选条件 -->
                            </form>
                        </div>

                        <!-- 商品网格布局 -->
                        <div class="product-grid">
                            <?php if (count($products) > 0): ?>
                                <?php foreach ($products as $product): ?>
                                    <!-- 单个商品容器 -->
                                    <div class="product-item-container">
                                        <?php if (!empty($product['image_path'])): ?>
                                            <!-- 商品图片 -->
                                            <img src="<?php echo htmlspecialchars($product['image_path']); ?>" 
                                                 alt="<?php echo htmlspecialchars($product['name']); ?>" 
                                                 class="product-image"
                                                 loading="lazy">  <!-- 懒加载 -->
                                        <?php else: ?>
                                            <!-- 默认图片占位 -->
                                            <div class="product-image" style="background: #eee; display: flex; align-items: center; justify-content: center;">
                                                暂无图片
                                            </div>
                                        <?php endif; ?>
                                        <!-- 商品标题 -->
                                        <h3 class="product-title"><?php echo htmlspecialchars($product['name']); ?></h3>
                                        <!-- 商品分类 -->
                                        <p class="product-category">分类: <?php echo htmlspecialchars($product['category_name'] ?? '未分类'); ?></p>
                                        <!-- 商品价格 -->
                                        <p class="product-price">HK$<?php echo number_format($product['price'], 2); ?></p>
                                        <!-- 商品状态 -->
                                        <p class="product-status">状态: <?php echo htmlspecialchars($product['status'] ?? '未知'); ?></p>
                                        <!-- 商品描述（截取前50字符） -->
                                        <p class="product-description"> <?php echo htmlspecialchars(mb_substr($product['description'] ?? '', 0, 50) . '...'); ?></p>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <!-- 无商品提示 -->
                                <div style="grid-column: 1 / -1; text-align: center; padding: 50px;">
                                    没有找到符合条件的商品
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- 分页导航 -->
                        <?php if ($totalPages > 1): ?>
                            <div class="pagination">
                                <?php if ($page > 1): ?>
                                    <!-- 首页链接 -->
                                    <a href="?page=1&category=
                                                    <?php echo $category_id; ?>
                                        &search=    <?php echo urlencode($search); ?>
                                        &min_price= <?php echo isset($_GET['min_price']) ? htmlspecialchars($_GET['min_price']) : ''; ?>
                                        &max_price= <?php echo isset($_GET['max_price']) ? htmlspecialchars($_GET['max_price']) : ''; ?>
                                        &status=    <?php echo isset($_GET['status'])    ? htmlspecialchars($_GET['status']) : ''; ?>">首页</a>
                                    <!-- 上一页链接 -->
                                    <a href="?page= <?php echo $page - 1; ?>
                                        &category=  <?php echo $category_id; ?>
                                        &search=    <?php echo urlencode($search); ?>
                                        &min_price= <?php echo isset($_GET['min_price']) ? htmlspecialchars($_GET['min_price']) : ''; ?>
                                        &max_price= <?php echo isset($_GET['max_price']) ? htmlspecialchars($_GET['max_price']) : ''; ?>
                                        &status=    <?php echo isset($_GET['status'])    ? htmlspecialchars($_GET['status']) : ''; ?>">上一页</a>
                                <?php endif; ?>
                                
                                <?php 
                                // 显示当前页附近的页码（前后各2页）
                                $start = max(1, $page - 2);
                                $end = min($totalPages, $page + 2);
                                
                                for ($i = $start; $i <= $end; $i++): ?>
                                    <?php if ($i == $page): ?>
                                        <!-- 当前页（不可点击） -->
                                <span class="current">  <?php echo $i; ?></span>
                                    <?php else: ?>
                                        <!-- 其他页码链接 -->
                                        <a href="?page= <?php echo $i; ?>
                                            &category=  <?php echo $category_id; ?>
                                            &search=    <?php echo urlencode($search); ?>
                                            &min_price= <?php echo isset($_GET['min_price']) ? htmlspecialchars($_GET['min_price']) : ''; ?>
                                            &max_price= <?php echo isset($_GET['max_price']) ? htmlspecialchars($_GET['max_price']) : ''; ?>
                                            &status=    <?php echo isset($_GET['status'])    ? htmlspecialchars($_GET['status']) : ''; ?>">
                                            <?php echo $i; ?></a>
                                    <?php endif; ?>
                                <?php endfor; ?>                                
                                    <?php if ($page < $totalPages): ?>
                                        <!-- 下一页链接 -->
                                        <a href="?page= <?php echo $page + 1; ?>
                                            &category=  <?php echo $category_id; ?>
                                            &search=    <?php echo urlencode($search); ?>
                                            &min_price= <?php echo isset($_GET['min_price']) ? htmlspecialchars($_GET['min_price']) : ''; ?>
                                            &max_price= <?php echo isset($_GET['max_price']) ? htmlspecialchars($_GET['max_price']) : ''; ?>
                                            &status=    <?php echo isset($_GET['status'])    ? htmlspecialchars($_GET['status']) : ''; ?>">下一页</a>
                                        <!-- 尾页链接 -->
                                        <a href="?page= <?php echo $totalPages; ?>
                                            &category=  <?php echo $category_id; ?>
                                            &search=    <?php echo urlencode($search); ?>
                                            &min_price= <?php echo isset($_GET['min_price']) ? htmlspecialchars($_GET['min_price']) : ''; ?>
                                            &max_price= <?php echo isset($_GET['max_price']) ? htmlspecialchars($_GET['max_price']) : ''; ?>
                                            &status=    <?php echo isset($_GET['status'])    ? htmlspecialchars($_GET['status']) : ''; ?>">尾页</a>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>