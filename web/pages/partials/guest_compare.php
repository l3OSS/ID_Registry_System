<?php /* Partial: view หน้าเปรียบเทียบข้อมูลซ้ำ - ใช้ $post_data, $old_data, $address_id จาก guest_check.php */ ?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title><?php echo e('cmp.page_title'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .compare-card { border-radius: 15px; overflow: hidden; }
        .old-data { background-color: #fff4f4; }
        .new-data { background-color: #f4fff4; }
    </style>
</head>
<body class="bg-light">
<div class="container mt-5 mb-5">
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <div class="alert alert-warning shadow-sm border-start border-5 border-warning mb-4">
                <div class="d-flex align-items-center">
                    <i class="bi bi-exclamation-triangle-fill fs-1 me-3"></i>
                    <div>
                        <h5 class="fw-bold mb-1"><?php echo e('cmp.dup_found'); ?></h5>
                        <p class="mb-0"><?php echo e('cmp.dup_question'); ?></p>
                    </div>
                </div>
            </div>

            
            <div class="card shadow-sm compare-card">
                <div class="card-header bg-dark text-white py-3">
                    <h5 class="mb-0"><i class="bi bi-arrow-left-right"></i> <?php echo e('cmp.compare'); ?></h5>
                </div>
                <form action="guest_check.php" method="POST">
                    <?= csrf_field() ?>
                    <?php 
                    renderHiddenInputs($post_data); 
                    if(!isset($post_data['address_id']) && isset($address_id)) {
                        echo '<input type="hidden" name="address_id" value="'.$address_id.'">';
                            }
                    ?>
                    <input type="hidden" name="existing_guest_id" value="<?php echo $old_data['id']; ?>">
                    
                    <div class="table-responsive">
                        <table class="table table-bordered mb-0">
                            <thead class="table-light">
                                <tr class="text-center">
                                    <th width="20%"><?php echo e('cmp.col_topic'); ?></th>
                                    <th width="40%"><?php echo e('cmp.col_old'); ?></th>
                                    <th width="40%"><?php echo e('cmp.col_new'); ?></th>
                                </tr>
                            </thead>
                            <?php
                            $diff_name = ($old_data['firstname'] !== $post_data['firstname'] || $old_data['lastname'] !== $post_data['lastname']);
                            $diff_addr = ($old_data['subdistrict'] !== $post_data['addr_tambon'] || $old_data['province'] !== $post_data['addr_province']);
                            ?>

                            

<div class="row g-0 border-bottom">
    <div class="col-6 p-3 text-center old-data">
        <img src="../<?php echo !empty($old_data['photo_path']) ? $old_data['photo_path'] : 'assets/noimg.jpg'; ?>" class="img-thumbnail" style="height: 150px;">
        <div class="small mt-1 text-muted"><?php echo e('cmp.old_photo'); ?></div>
    </div>
    <div class="col-6 p-3 text-center new-data">
        <img src="<?php echo htmlspecialchars(!empty($post_data['photo_base64']) ? 'data:image/jpeg;base64,' . preg_replace('#^data:image/\w+;base64,#i', '', $post_data['photo_base64']) : '../assets/noimg.jpg'); ?>" class="img-thumbnail" style="height: 150px;">
        <div class="small mt-1 text-muted"><?php echo e('cmp.new_photo'); ?></div>
    </div>
</div>

                            <tbody>
                                <tr>
                                    <td class="fw-bold bg-light"><?php echo e('cmp.fullname'); ?></td>
                                    <td class="text-center old-data"><?php echo htmlspecialchars($old_data['prefix'].$old_data['firstname'].' '.$old_data['lastname']); ?></td>
                                    <td class="text-center new-data <?php echo $diff_name ? 'text-danger fw-bold' : ''; ?>">
                                        <?php echo htmlspecialchars($post_data['prefix'].$post_data['firstname'].' '.$post_data['lastname']); ?>
                                        <?php echo $diff_name ? ' <i class="bi bi-exclamation-circle"></i>' : ''; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="fw-bold bg-light"><?php echo e('cmp.id_card'); ?></td>
                                    <td class="text-center old-data"><?php echo decryptData($old_data['id_card_enc']); ?></td>
                                    <td class="text-center new-data"><?php echo htmlspecialchars($post_data['id_card']); ?></td>
                                </tr>
                                <tr>
                                    <td class="fw-bold bg-light"><?php echo e('cmp.address'); ?></td>
                                    <td class="text-center old-data"><?php echo ($old_data['subdistrict']) ? htmlspecialchars(t('addr.tambon')."{$old_data['subdistrict']} ".t('addr.amphoe')."{$old_data['district']} ".t('addr.changwat')."{$old_data['province']}") : e('cmp.not_specified'); ?></td>
                                    <td class="text-center new-data"> <?php echo htmlspecialchars(t('addr.tambon')."{$post_data['addr_tambon']} ".t('addr.amphoe')."{$post_data['addr_amphoe']} ".t('addr.changwat')."{$post_data['addr_province']}"); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="card-footer bg-white p-4">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <button type="submit" name="decision" value="update" class="btn btn-success w-100 py-3 fw-bold shadow-sm">
                                    <i class="bi bi-pencil-square"></i> <?php echo e('cmp.btn_update'); ?>
                                </button>
                            </div>
                            <div class="col-md-4">
                                <button type="submit" name="decision" value="keep_old" class="btn btn-primary w-100 py-3 fw-bold shadow-sm">
                                    <i class="bi bi-shield-check"></i> <?php echo e('cmp.btn_keep'); ?>
                                </button>
                            </div>
                            <div class="col-md-4">
                                <a href="../index.php?page=guest_form" class="btn btn-outline-secondary w-100 py-3">
                                    <i class="bi bi-x-circle"></i> <?php echo e('btn.cancel'); ?>
                                </a>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
</body>
</html>