*   **`report_products.php`:** (`report_customers.php`'ye benzer, farklı metrikler)

    ```php
    <?php
    // report_products.php - Ürün/Hizmet raporları sayfası
    require_once 'config/database.php';
    require_once 'includes/session.php';

    // Kullanıcı girişi gerekli
    requireLogin();

    $conn = getDbConnection();

    // Filtreler
    $minStock = isset($_GET['min_stock']) && is_numeric($_GET['min_stock']) ? intval($_GET['min_stock']) : null;
    $maxStock = isset($_GET['max_stock']) && is_numeric($_GET['max_stock']) ? intval($_GET['max_stock']) : null;

    // Rapor verilerini çek (Ürünler için)
    $productReports = [];
    try {
        $sql = "SELECT
                    p.id,
                    p.code,
                    p.name,
                    p.price,
                    p.stock_quantity,
                    (p.price * p.stock_quantity) AS stock_value,
                    COUNT(DISTINCT qi.quotation_id) AS times_quoted,
                    SUM(qi.quantity) AS total_quoted_quantity,
                    SUM(qi.subtotal) AS total_quoted_value -- KDV ve indirim hariç ara toplam
                FROM products p
                LEFT JOIN quotation_items qi ON p.id = qi.item_id AND qi.item_type = 'product' ";

        $conditions = [];
        $params = [];

         if ($minStock !== null) {
            $conditions[] = "p.stock_quantity >= :min_stock";
            $params[':min_stock'] = $minStock;
        }
         if ($maxStock !== null) {
            $conditions[] = "p.stock_quantity <= :max_stock";
            $params[':max_stock'] = $maxStock;
        }

         if(!empty($conditions)){
             $sql .= " WHERE " . implode(" AND ", $conditions);
        }


        $sql .= " GROUP BY p.id, p.code, p.name, p.price, p.stock_quantity ";
        $sql .= " ORDER BY total_quoted_value DESC, times_quoted DESC, p.name ASC";

        $stmt = $conn->prepare($sql);
         foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $productReports = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch(PDOException $e) {
        setMessage('error', 'Ürün rapor verileri alınırken hata oluştu: ' . $e->getMessage());
    }

     // Excel export işlemi (report_quotations.php'den uyarlanabilir)
    if (isset($_GET['export']) && $_GET['export'] == 'excel') {
        require_once 'vendor/autoload.php';
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Ürün Raporu');

        $sheet->setCellValue('A1', 'ID');
        $sheet->setCellValue('B1', 'Kod');
        $sheet->setCellValue('C1', 'Ad');
        $sheet->setCellValue('D1', 'Fiyat (₺)');
        $sheet->setCellValue('E1', 'Stok');
        $sheet->setCellValue('F1', 'Stok Değeri (₺)');
        $sheet->setCellValue('G1', 'Teklif Sayısı');
        $sheet->setCellValue('H1', 'Teklif Edilen Miktar');
        $sheet->setCellValue('I1', 'Teklif Edilen Değer (₺)'); // Ara toplam
        $sheet->getStyle('A1:I1')->getFont()->setBold(true);

        $row = 2;
        foreach ($productReports as $report) {
            $sheet->setCellValue('A' . $row, $report['id']);
            $sheet->setCellValue('B' . $row, $report['code']);
            $sheet->setCellValue('C' . $row, $report['name']);
            $sheet->setCellValue('D' . $row, $report['price']);
            $sheet->setCellValue('E' . $row, $report['stock_quantity']);
            $sheet->setCellValue('F' . $row, $report['stock_value']);
            $sheet->setCellValue('G' . $row, $report['times_quoted']);
            $sheet->setCellValue('H' . $row, $report['total_quoted_quantity'] ?: 0);
            $sheet->setCellValue('I' . $row, $report['total_quoted_value'] ?: 0);
            $row++;
        }

        foreach (range('A', 'I') as $col) {
             $sheet->getColumnDimension($col)->setAutoSize(true);
        }
         $sheet->getStyle('D2:D'.$row)->getNumberFormat()->setFormatCode('#,##0.00" ₺"');
         $sheet->getStyle('F2:F'.$row)->getNumberFormat()->setFormatCode('#,##0.00" ₺"');
         $sheet->getStyle('I2:I'.$row)->getNumberFormat()->setFormatCode('#,##0.00" ₺"');


        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="Urun_Raporu_' . date('Y-m-d') . '.xlsx"');
        header('Cache-Control: max-age=0');
        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save('php://output');
        exit;
    }


    $pageTitle = 'Ürün Raporları';
    $currentPage = 'reports';
    include 'includes/header.php';
    include 'includes/navbar.php';
    include 'includes/sidebar.php';
    ?>

    <!-- Main Content -->
    <div class="main-content">
        <div class="container-fluid">
             <!-- Bildirimler -->
             <?php if ($successMessage = getMessage('success')): ?>
                <div class="alert alert-success"><?php echo $successMessage; ?></div>
            <?php endif; ?>
             <?php if ($errorMessage = getMessage('error')): ?>
                <div class="alert alert-danger"><?php echo $errorMessage; ?></div>
            <?php endif; ?>

            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="h2">Ürün Raporları</h1>
                  <div>
                    <a href="reports.php" class="btn btn-secondary me-2">
                        <i class="bi bi-arrow-left"></i> Raporlara Dön
                    </a>
                     <a href="<?php echo htmlspecialchars($_SERVER['REQUEST_URI'] . (strpos($_SERVER['REQUEST_URI'], '?') !== false ? '&' : '?') . 'export=excel'); ?>" class="btn btn-success">
                        <i class="bi bi-file-excel"></i> Excel'e Aktar
                    </a>
                </div>
            </div>

            <!-- Filtreler -->
            <div class="filter-form">
                 <form method="get" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" class="row g-3">
                    <div class="col-md-3">
                        <label for="min_stock" class="form-label">Minimum Stok</label>
                        <input type="number" class="form-control" id="min_stock" name="min_stock" min="0" value="<?php echo $minStock; ?>">
                    </div>
                     <div class="col-md-3">
                        <label for="max_stock" class="form-label">Maksimum Stok</label>
                        <input type="number" class="form-control" id="max_stock" name="max_stock" min="0" value="<?php echo $maxStock; ?>">
                    </div>
                    <div class="col-md-6 align-self-end text-end">
                        <button type="submit" class="btn btn-primary">Filtrele</button>
                        <a href="report_products.php" class="btn btn-secondary">Sıfırla</a>
                    </div>
                </form>
            </div>

            <!-- Rapor Tablosu -->
            <div class="card mt-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Ürün Analizi</h5>
                     <span>Toplam: <?php echo count($productReports); ?> ürün</span>
                </div>
                <div class="card-body p-0">
                     <?php if (count($productReports) > 0): ?>
                        <div class="table-responsive">
                             <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Kod</th>
                                        <th>Ad</th>
                                        <th>Fiyat (₺)</th>
                                        <th>Stok</th>
                                        <th>Stok Değeri (₺)</th>
                                        <th>Teklif Sayısı</th>
                                        <th>Teklif Edilen Miktar</th>
                                        <th>Teklif Edilen Değer (₺)</th>
                                        <th>İşlemler</th>
                                    </tr>
                                </thead>
                                <tbody>
                                     <?php foreach ($productReports as $report): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($report['code']); ?></td>
                                            <td><?php echo htmlspecialchars($report['name']); ?></td>
                                            <td class="text-end"><?php echo number_format($report['price'], 2, ',', '.'); ?></td>
                                            <td class="text-center"><?php echo $report['stock_quantity']; ?></td>
                                            <td class="text-end"><?php echo number_format($report['stock_value'], 2, ',', '.'); ?></td>
                                            <td class="text-center"><?php echo $report['times_quoted']; ?></td>
                                            <td class="text-center"><?php echo $report['total_quoted_quantity'] ?: 0; ?></td>
                                            <td class="text-end"><?php echo number_format($report['total_quoted_value'] ?: 0, 2, ',', '.'); ?></td>
                                             <td>
                                                <a href="view_product.php?id=<?php echo $report['id']; ?>" class="btn btn-sm btn-info" title="Görüntüle">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                 <a href="inventory.php?product_id=<?php echo $report['id']; ?>" class="btn btn-sm btn-primary" title="Stok Hareketleri">
                                                    <i class="bi bi-clipboard-data"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="p-3 text-center">
                            <p>Seçilen kriterlere uygun ürün raporu bulunamadı.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <!-- Hizmet raporları için benzer bir tablo eklenebilir -->
             <!-- Buraya grafikler eklenebilir (örn. en çok teklif edilen ürünler, stok değeri yüksek ürünler) -->

        </div>
    </div>

    <?php include 'includes/footer.php'; ?>