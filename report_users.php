<?php
    // report_users.php - Kullanıcı performans raporları sayfası
    require_once 'config/database.php';
    require_once 'includes/session.php';

    // Admin girişi gerekli
    requireAdmin();

    $conn = getDbConnection();

    // Filtreler
    $startDate = isset($_GET['start_date']) ? $_GET['start_date'] : null;
    $endDate = isset($_GET['end_date']) ? $_GET['end_date'] : null;
    $selectedUserID = isset($_GET['user_id']) && is_numeric($_GET['user_id']) ? intval($_GET['user_id']) : 0;


     // Kullanıcıları getir (filtre için)
    $users = [];
    try {
        $stmt = $conn->query("SELECT id, username, full_name FROM users ORDER BY username ASC");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        setMessage('error', 'Kullanıcı listesi alınırken bir hata oluştu: ' . $e->getMessage());
    }


    // Rapor verilerini çek
    $userReports = [];
    try {
        $sql = "SELECT
                    u.id,
                    u.username,
                    u.full_name,
                    COUNT(q.id) AS total_quotations,
                    SUM(CASE WHEN q.status = 'accepted' THEN 1 ELSE 0 END) AS accepted_quotations,
                    SUM(CASE WHEN q.status = 'rejected' THEN 1 ELSE 0 END) AS rejected_quotations,
                    SUM(CASE WHEN q.status = 'accepted' THEN q.total_amount ELSE 0 END) AS total_accepted_amount,
                    SUM(q.total_amount) AS total_quotation_amount,
                     MAX(q.date) AS last_quotation_date
                FROM users u
                LEFT JOIN quotations q ON u.id = q.user_id "; // Tüm kullanıcıları al, teklifi olmasa bile

        $conditions = [];
        $params = [];

         // Tarih filtresini LEFT JOIN'den sonraki WHERE'e ekle, ancak sadece teklifleri filtrele
        if ($startDate) {
            $conditions[] = "q.date >= :start_date";
            $params[':start_date'] = $startDate;
        }
         if ($endDate) {
            $conditions[] = "q.date <= :end_date";
            $params[':end_date'] = $endDate;
        }
         // Kullanıcı filtresi
         if ($selectedUserID > 0) {
            $conditions[] = "u.id = :user_id";
             $params[':user_id'] = $selectedUserID;
        }


         if(!empty($conditions)){
             // Eğer tarih veya kullanıcı filtresi varsa, WHERE koşulunu ekle
             $sql .= " WHERE " . implode(" AND ", $conditions);
        }


        $sql .= " GROUP BY u.id, u.username, u.full_name ";
        $sql .= " ORDER BY total_accepted_amount DESC, total_quotations DESC, u.username ASC";

        $stmt = $conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $userReports = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch(PDOException $e) {
        setMessage('error', 'Kullanıcı rapor verileri alınırken hata oluştu: ' . $e->getMessage());
    }

      // Excel export işlemi (uyarlanabilir)
    if (isset($_GET['export']) && $_GET['export'] == 'excel') {
        require_once 'vendor/autoload.php';
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Kullanıcı Performans Raporu');

        $sheet->setCellValue('A1', 'Kullanıcı ID');
        $sheet->setCellValue('B1', 'Kullanıcı Adı');
        $sheet->setCellValue('C1', 'Ad Soyad');
        $sheet->setCellValue('D1', 'Toplam Teklif');
        $sheet->setCellValue('E1', 'Kabul Edilen Teklif');
        $sheet->setCellValue('F1', 'Reddedilen Teklif');
        $sheet->setCellValue('G1', 'Başarı Oranı (%)');
        $sheet->setCellValue('H1', 'Kabul Edilen Tutar (₺)');
        $sheet->setCellValue('I1', 'Toplam Teklif Tutarı (₺)');
        $sheet->setCellValue('J1', 'Son Teklif Tarihi');

        $sheet->getStyle('A1:J1')->getFont()->setBold(true);

        $row = 2;
        foreach ($userReports as $report) {
             $totalCreated = $report['accepted_quotations'] + $report['rejected_quotations']; // Veya total_quotations kullanılabilir
             $successRate = ($totalCreated > 0) ? round(($report['accepted_quotations'] / $totalCreated) * 100, 2) : 0;

            $sheet->setCellValue('A' . $row, $report['id']);
            $sheet->setCellValue('B' . $row, $report['username']);
            $sheet->setCellValue('C' . $row, $report['full_name']);
            $sheet->setCellValue('D' . $row, $report['total_quotations']);
            $sheet->setCellValue('E' . $row, $report['accepted_quotations']);
            $sheet->setCellValue('F' . $row, $report['rejected_quotations']);
            $sheet->setCellValue('G' . $row, $successRate);
            $sheet->setCellValue('H' . $row, $report['total_accepted_amount']);
            $sheet->setCellValue('I' . $row, $report['total_quotation_amount']);
             $sheet->setCellValue('J' . $row, $report['last_quotation_date']);

            $row++;
        }

        foreach (range('A', 'J') as $col) {
             $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->getStyle('H2:I'.$row)->getNumberFormat()->setFormatCode('#,##0.00" ₺"');
         $sheet->getStyle('G2:G'.$row)->getNumberFormat()->setFormatCode('0.00"%"');


        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="Kullanici_Performans_Raporu_' . date('Y-m-d') . '.xlsx"');
        header('Cache-Control: max-age=0');
        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save('php://output');
        exit;
    }

    $pageTitle = 'Kullanıcı Performans Raporları';
    $currentPage = 'reports'; // Veya 'users' altında da olabilir, menüye göre ayarlayın
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
                <h1 class="h2">Kullanıcı Performans Raporları</h1>
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
                        <label for="user_id" class="form-label">Kullanıcı</label>
                        <select class="form-select" id="user_id" name="user_id">
                            <option value="0">Tüm Kullanıcılar</option>
                             <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user['id']; ?>" <?php echo $selectedUserID == $user['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($user['full_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="start_date" class="form-label">Başlangıç Tarihi (Teklif)</label>
                        <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo $startDate; ?>">
                    </div>
                    <div class="col-md-3">
                        <label for="end_date" class="form-label">Bitiş Tarihi (Teklif)</label>
                        <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo $endDate; ?>">
                    </div>
                    <div class="col-md-3 align-self-end">
                        <button type="submit" class="btn btn-primary">Filtrele</button>
                        <a href="report_users.php" class="btn btn-secondary">Sıfırla</a>
                    </div>
                </form>
            </div>

            <!-- Rapor Tablosu -->
            <div class="card mt-4">
                 <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Kullanıcı Performansı</h5>
                    <span>Toplam: <?php echo count($userReports); ?> kullanıcı</span>
                </div>
                 <div class="card-body p-0">
                     <?php if (count($userReports) > 0): ?>
                        <div class="table-responsive">
                             <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Kullanıcı</th>
                                        <th>Toplam Teklif</th>
                                        <th>Kabul Edilen</th>
                                        <th>Reddedilen</th>
                                        <th>Başarı Oranı (%)</th>
                                        <th>Kabul Edilen Tutar (₺)</th>
                                         <th>Ort. Teklif Tutarı (₺)</th>
                                         <th>Son Teklif Tarihi</th>
                                         <th>İşlemler</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($userReports as $report):
                                         $totalCreated = $report['accepted_quotations'] + $report['rejected_quotations']; // Sadece kabul/red üzerinden oran
                                        $successRate = ($totalCreated > 0) ? round(($report['accepted_quotations'] / $totalCreated) * 100, 2) : 0;
                                        $avgAmount = ($report['total_quotations'] > 0) ? round($report['total_quotation_amount'] / $report['total_quotations'], 2) : 0;
                                    ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($report['full_name']); ?></td>
                                            <td class="text-center"><?php echo $report['total_quotations']; ?></td>
                                            <td class="text-center text-success"><?php echo $report['accepted_quotations']; ?></td>
                                            <td class="text-center text-danger"><?php echo $report['rejected_quotations']; ?></td>
                                            <td class="text-center"><?php echo $successRate; ?>%</td>
                                            <td class="text-end"><?php echo number_format($report['total_accepted_amount'], 2, ',', '.'); ?></td>
                                             <td class="text-end"><?php echo number_format($avgAmount, 2, ',', '.'); ?></td>
                                             <td><?php echo $report['last_quotation_date'] ? date('d.m.Y', strtotime($report['last_quotation_date'])) : '-'; ?></td>
                                              <td>
                                                 <a href="quotations.php?user=<?php echo $report['id']; ?>" class="btn btn-sm btn-primary" title="Teklifleri Gör"> <!-- quotations.php'ye user filtresi eklenmeli -->
                                                    <i class="bi bi-file-text"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="p-3 text-center">
                            <p>Seçilen kriterlere uygun kullanıcı raporu bulunamadı.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
             <!-- Buraya grafikler eklenebilir (örn. en başarılı kullanıcılar) -->

        </div>
    </div>

    <?php include 'includes/footer.php'; ?>