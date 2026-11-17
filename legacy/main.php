<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Listings</title>
    <link rel="stylesheet" href="CSS/style3.css" />
</head>
<body>
    <div class="main">
        <div class="main-nav-position">
            <div class="main-nav">
                <div class="main-nav-logo half-nav"></div>
                <div class="main-nav-seach">
                    <form method="get" action="" class="search-form">
                        <input type="text" name="search" placeholder="Search listings..." 
                               value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>" 
                               class="search-input">
                        <button type="submit" class="search-button">Search</button>
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
                    // Constant: number of listings shown per page
                    define('PER_PAGE', 12);

                    // Read the current page number, defaulting to 1 and never below 1
                    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
                    $page = max(1, $page);

                    // Read the category filter, defaulting to 0 (all)
                    $category_id = isset($_GET['category']) ? (int)$_GET['category'] : 0;

                    // Read the search keyword and strip leading/trailing whitespace
                    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
                    
                    // Read the price range filter conditions
                    $min_price = isset($_GET['min_price']) ? (float)$_GET['min_price'] : null;
                    $max_price = isset($_GET['max_price']) ? (float)$_GET['max_price'] : null;
                    
                    // Read the listing status filter condition
                    $status = isset($_GET['status']) ? $_GET['status'] : '';

                    /**
                     * Fetch listing data
                     * @param PDO $conn Database connection
                     * @param int $page Current page number
                     * @param int $perPage Number of items per page
                     * @param int $category_id Category ID
                     * @param string $search Search keyword
                     * @param float|null $min_price Minimum price
                     * @param float|null $max_price Maximum price
                     * @param string $status Listing status
                     * @return array Array containing the listing data and total count
                     */
                    function getProducts($conn, $page, $perPage, $category_id = 0, $search = '', $min_price = null, $max_price = null, $status = '') {
                        $offset = ($page - 1) * $perPage;  // Calculate the offset
                        
                        // Build the query conditions and the parameter array
                        $where = [];
                        $params = [];
                        
                        // If a category filter is present
                        if ($category_id > 0) {
                            $where[] = "p.category_id = :category_id";
                            $params[':category_id'] = $category_id;
                        }
                        
                        // If a search keyword is present
                        if (!empty($search)) {
                            $where[] = "(p.name LIKE :search OR p.description LIKE :search)";
                            $params[':search'] = "%{$search}%";
                        }
                        
                        // Add the price range filter
                        if ($min_price !== null && $min_price > 0) {
                            $where[] = "p.price >= :min_price";
                            $params[':min_price'] = $min_price;
                        }
                        
                        if ($max_price !== null && $max_price > 0) {
                            $where[] = "p.price <= :max_price";
                            $params[':max_price'] = $max_price;
                        }
                        
                        // Add the listing status filter
                        if (!empty($status)) {
                            $where[] = "p.status = :status";
                            $params[':status'] = $status;
                        }
                        
                        // Combine the WHERE clause
                        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
                        
                        // Query the total number of listings
                        $countQuery = "SELECT COUNT(*) as total FROM products p $whereClause";
                        $stmt = $conn->prepare($countQuery);
                        $stmt->execute($params);
                        $total = $stmt->fetchColumn();
                        
                        // Query the listing data
                        $query = "SELECT p.*, c.name as category_name 
                                  FROM products p 
                                  LEFT JOIN categories c ON p.category_id = c.id 
                                  $whereClause 
                                  ORDER BY p.created_at DESC 
                                  LIMIT :offset, :perPage";
                        
                        // Add the pagination parameters
                        $params[':offset'] = $offset;
                        $params[':perPage'] = $perPage;
                        
                        $stmt = $conn->prepare($query);
                        
                        // Bind the parameters
                        foreach ($params as $key => $value) {
                            $paramType = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
                            $stmt->bindValue($key, $value, $paramType);
                        }
                        
                        $stmt->execute();
                        return ['products' => $stmt->fetchAll(), 'total' => $total];
                    }

                    /**
                     * Fetch all category data
                     * @param PDO $conn Database connection
                     * @return array Array of categories
                     */
                    function getCategories($conn) {
                        $stmt = $conn->query("SELECT * FROM categories ORDER BY name");
                        return $stmt->fetchAll();
                    }

                    // Fetch the listing data
                    $data = getProducts($conn, $page, PER_PAGE, $category_id, $search, $min_price, $max_price, $status);
                    $products = $data['products'];
                    $total = $data['total'];
                    
                    // Fetch the category data
                    $categories = getCategories($conn);
                    
                    // Calculate the total number of pages
                    $totalPages = ceil($total / PER_PAGE);
                ?>
                    <div class="product-display-container">
                        <!-- Search and filter form -->
                        <div class="search-filter">
                            <form method="get" action="">
                                <!-- Category dropdown -->
                                <select name="category">
                                    <option value="0">All categories</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?php echo $category['id']; ?>" <?php echo $category_id == $category['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($category['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                
                                <!-- Price range filter -->
                                <div class="price-filter">
                                    <label>PRICE</label>
                                    <input type="number" name="min_price" placeholder="MIN" 
                                           value="<?php echo isset($_GET['min_price']) ? htmlspecialchars($_GET['min_price']) : ''; ?>">
                                    <span> - </span>
                                    <input type="number" name="max_price" placeholder="MAX" 
                                           value="<?php echo isset($_GET['max_price']) ? htmlspecialchars($_GET['max_price']) : ''; ?>">
                                </div>
                                
                                <!-- Listing status filter -->
                                <div class="status-filter">
                                    <label>STATUS</label>
                                    <select name="status">
                                        <option value="">ALL</option>
                                        <option value="NEW" <?php echo isset($_GET['status']) && $_GET['status'] == 'NEW' ? 'selected' : ''; ?>>NEW</option>
                                        <option value="USED" <?php echo isset($_GET['status']) && $_GET['status'] == 'USED' ? 'selected' : ''; ?>>USED</option>
                                        <option value="BROKE" <?php echo isset($_GET['status']) && $_GET['status'] == 'BROKE' ? 'selected' : ''; ?>>BROKE</option>
                                    </select>
                                </div>
                                
                                <button type="submit">FILTER</button>
                                <a href="?" style="margin-left: 10px;">RESET</a>  <!-- Reset the filter conditions -->
                            </form>
                        </div>

                        <!-- Listing grid layout -->
                        <div class="product-grid">
                            <?php if (count($products) > 0): ?>
                                <?php foreach ($products as $product): ?>
                                    <!-- Single listing container -->
                                    <div class="product-item-container">
                                        <?php if (!empty($product['image_path'])): ?>
                                            <!-- Listing image -->
                                            <img src="<?php echo htmlspecialchars($product['image_path']); ?>" 
                                                 alt="<?php echo htmlspecialchars($product['name']); ?>" 
                                                 class="product-image"
                                                 loading="lazy">  <!-- Lazy loading -->
                                        <?php else: ?>
                                            <!-- Default image placeholder -->
                                            <div class="product-image" style="background: #eee; display: flex; align-items: center; justify-content: center;">
                                                No image available
                                            </div>
                                        <?php endif; ?>
                                        <!-- Listing title -->
                                        <h3 class="product-title"><?php echo htmlspecialchars($product['name']); ?></h3>
                                        <!-- Listing category -->
                                        <p class="product-category">Category: <?php echo htmlspecialchars($product['category_name'] ?? 'Uncategorized'); ?></p>
                                        <!-- Listing price -->
                                        <p class="product-price">HK$<?php echo number_format($product['price'], 2); ?></p>
                                        <!-- Listing status -->
                                        <p class="product-status">Status: <?php echo htmlspecialchars($product['status'] ?? 'Unknown'); ?></p>
                                        <!-- Listing description (first 50 characters) -->
                                        <p class="product-description"> <?php echo htmlspecialchars(mb_substr($product['description'] ?? '', 0, 50) . '...'); ?></p>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <!-- No listings message -->
                                <div style="grid-column: 1 / -1; text-align: center; padding: 50px;">
                                    No listings match your criteria
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Pagination navigation -->
                        <?php if ($totalPages > 1): ?>
                            <div class="pagination">
                                <?php if ($page > 1): ?>
                                    <!-- First page link -->
                                    <a href="?page=1&category=
                                                    <?php echo $category_id; ?>
                                        &search=    <?php echo urlencode($search); ?>
                                        &min_price= <?php echo isset($_GET['min_price']) ? htmlspecialchars($_GET['min_price']) : ''; ?>
                                        &max_price= <?php echo isset($_GET['max_price']) ? htmlspecialchars($_GET['max_price']) : ''; ?>
                                        &status=    <?php echo isset($_GET['status'])    ? htmlspecialchars($_GET['status']) : ''; ?>">First</a>
                                    <!-- Previous page link -->
                                    <a href="?page= <?php echo $page - 1; ?>
                                        &category=  <?php echo $category_id; ?>
                                        &search=    <?php echo urlencode($search); ?>
                                        &min_price= <?php echo isset($_GET['min_price']) ? htmlspecialchars($_GET['min_price']) : ''; ?>
                                        &max_price= <?php echo isset($_GET['max_price']) ? htmlspecialchars($_GET['max_price']) : ''; ?>
                                        &status=    <?php echo isset($_GET['status'])    ? htmlspecialchars($_GET['status']) : ''; ?>">Prev</a>
                                <?php endif; ?>
                                
                                <?php 
                                // Show the page numbers near the current page (2 before and after)
                                $start = max(1, $page - 2);
                                $end = min($totalPages, $page + 2);
                                
                                for ($i = $start; $i <= $end; $i++): ?>
                                    <?php if ($i == $page): ?>
                                        <!-- Current page (not clickable) -->
                                <span class="current">  <?php echo $i; ?></span>
                                    <?php else: ?>
                                        <!-- Other page number links -->
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
                                        <!-- Next page link -->
                                        <a href="?page= <?php echo $page + 1; ?>
                                            &category=  <?php echo $category_id; ?>
                                            &search=    <?php echo urlencode($search); ?>
                                            &min_price= <?php echo isset($_GET['min_price']) ? htmlspecialchars($_GET['min_price']) : ''; ?>
                                            &max_price= <?php echo isset($_GET['max_price']) ? htmlspecialchars($_GET['max_price']) : ''; ?>
                                            &status=    <?php echo isset($_GET['status'])    ? htmlspecialchars($_GET['status']) : ''; ?>">Next</a>
                                        <!-- Last page link -->
                                        <a href="?page= <?php echo $totalPages; ?>
                                            &category=  <?php echo $category_id; ?>
                                            &search=    <?php echo urlencode($search); ?>
                                            &min_price= <?php echo isset($_GET['min_price']) ? htmlspecialchars($_GET['min_price']) : ''; ?>
                                            &max_price= <?php echo isset($_GET['max_price']) ? htmlspecialchars($_GET['max_price']) : ''; ?>
                                            &status=    <?php echo isset($_GET['status'])    ? htmlspecialchars($_GET['status']) : ''; ?>">Last</a>
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