<?php
/**
 * Learn Way - Tableau de bord de l'Étudiant
 */
$pageTitle = "Espace Apprenant";
require_once __DIR__ . '/../includes/header.php';
requireRole('etudiant');

$studentId  = intval($_SESSION['user_id']);
$successMsg = $_SESSION['action_success'] ?? null;
$errorMsg   = $_SESSION['action_error'] ?? null;
unset($_SESSION['action_success'], $_SESSION['action_error']);

// --- Inscription à un module (POST simple) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'enroll') {
    verifyCSRFToken();
    $mid = intval($_POST['module_id'] ?? 0);
    if ($mid > 0) {
        try {
            $s = $pdo->prepare('INSERT IGNORE INTO enrollments (student_id, module_id) VALUES (:sid, :mid)');
            $s->execute(['sid' => $studentId, 'mid' => $mid]);
            $_SESSION['action_success'] = "Inscription réussie !";
        } catch (PDOException $e) {
            $_SESSION['action_error'] = "Erreur lors de l'inscription.";
        }
    }
    header('Location: /dashboards/etudiant.php'); exit;
}

// ---- Données ----

// 1. Tous les modules disponibles
$allModules = $pdo->query('SELECT * FROM modules ORDER BY title')->fetchAll();

// 2. Modules auxquels l'étudiant est inscrit
$stmtEnrolled = $pdo->prepare('
    SELECT m.*, e.enrolled_at,
           (SELECT certificate_number FROM certifications WHERE student_id = :sid AND module_id = m.id) as cert_num
    FROM modules m
    JOIN enrollments e ON m.id = e.module_id
    WHERE e.student_id = :sid2
    ORDER BY e.enrolled_at DESC
');
$stmtEnrolled->execute(['sid' => $studentId, 'sid2' => $studentId]);
$enrolledModules = $stmtEnrolled->fetchAll();
$enrolledIds = array_column($enrolledModules, 'id');

// 3. Cours + leçons pour les modules inscrits
$lessonsByModule = [];
if (!empty($enrolledIds)) {
    $inList = implode(',', array_map('intval', $enrolledIds));
    $stmtLess = $pdo->query("
        SELECT l.*, c.title as course_title, c.id as course_id, c.module_id,
               e.id as eval_id, e.title as eval_title
        FROM lessons l
        JOIN courses c ON l.course_id = c.id
        LEFT JOIN evaluations e ON l.id = e.lesson_id
        WHERE c.module_id IN ($inList)
        ORDER BY c.module_id, c.id, l.position
    ");
    foreach ($stmtLess->fetchAll() as $row) {
        $lessonsByModule[$row['module_id']][] = $row;
    }
}

// 4. Meilleures tentatives de l'étudiant (pour affichage de progression)
$stmtAttempts = $pdo->prepare('
    SELECT evaluation_id, MAX(percentage) as best_pct, MAX(score_obtained) as best_score, MAX(max_score) as max_score
    FROM evaluation_attempts WHERE student_id = :sid GROUP BY evaluation_id
');
$stmtAttempts->execute(['sid' => $studentId]);
$attempts = [];
foreach ($stmtAttempts->fetchAll() as $row) {
    $attempts[$row['evaluation_id']] = $row;
}

/**
 * Calcule la progression globale d'un module pour l'étudiant courant
 */
function calcModuleProgress(array $lessons, array $attempts): float {
    if (empty($lessons)) return 0.0;
    $total = 0;
    foreach ($lessons as $l) {
        if (empty($l['eval_id'])) {
            $total += 100;
        } else {
            $total += isset($attempts[$l['eval_id']]) ? floatval($attempts[$l['eval_id']]['best_pct']) : 0;
        }
    }
    return round($total / count($lessons), 1);
}

// 5. Récupérer questions + options pour les QCM disponibles
$evalIds = array_filter(array_unique(array_merge(...array_values(array_map(fn($lls) => array_column($lls, 'eval_id'), $lessonsByModule ?: [[]])))));
$quizData = [];
if (!empty($evalIds)) {
    foreach ($evalIds as $eid) {
        if (!$eid) continue;
        $stmtQ = $pdo->prepare('SELECT id, question_text, points FROM questions WHERE evaluation_id = :eid');
        $stmtQ->execute(['eid' => $eid]);
        $qs = $stmtQ->fetchAll();
        foreach ($qs as &$q) {
            $stmtO = $pdo->prepare('SELECT id, option_text FROM options WHERE question_id = :qid');
            $stmtO->execute(['qid' => $q['id']]);
            $q['options'] = $stmtO->fetchAll();
        }
        $quizData[$eid] = $qs;
    }
}
?>

<?php if ($successMsg): ?>
<div class="alert alert-success alert-auto-dismiss glass-panel">
    <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    <span><?= htmlspecialchars($successMsg) ?></span>
</div>
<?php endif; ?>
<?php if ($errorMsg): ?>
<div class="alert alert-danger alert-auto-dismiss glass-panel">
    <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01"/></svg>
    <span><?= htmlspecialchars($errorMsg) ?></span>
</div>
<?php endif; ?>

<!-- Statistiques rapides -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon primary">
            <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
        </div>
        <div class="stat-details">
            <span class="stat-number"><?= count($enrolledModules) ?></span>
            <span class="stat-label">Modules inscrits</span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon success">
            <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
        </div>
        <div class="stat-details">
            <span class="stat-number"><?= count(array_filter($enrolledModules, fn($m) => !empty($m['cert_num']))) ?></span>
            <span class="stat-label">Certificats obtenus</span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon warning">
            <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
        </div>
        <div class="stat-details">
            <span class="stat-number"><?= count($attempts) ?></span>
            <span class="stat-label">Évaluations passées</span>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- MES MODULES INSCRITS (avec progression) -->
<!-- ============================================================ -->
<div class="card glass-panel" style="margin-bottom:2rem;">
    <div class="card-header">
        <h2 class="card-title">Ma Progression</h2>
    </div>
    <?php if (empty($enrolledModules)): ?>
        <p style="color:var(--text-secondary);padding:1rem 0;">Vous n'êtes inscrit à aucun module. Consultez le catalogue ci-dessous.</p>
    <?php else: ?>
        <?php foreach ($enrolledModules as $mod):
            $modLessons = $lessonsByModule[$mod['id']] ?? [];
            $modProgress = calcModuleProgress($modLessons, $attempts);
        ?>
        <div class="glass-card" style="padding:1.5rem;margin-bottom:1.25rem;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;margin-bottom:1rem;">
                <div>
                    <h3 style="color:#fff;font-size:1.05rem;margin-bottom:.3rem;"><?= htmlspecialchars($mod['title']) ?></h3>
                    <p style="color:var(--text-secondary);font-size:.85rem;"><?= htmlspecialchars(substr($mod['description'] ?? '', 0, 120)) ?></p>
                </div>
                <div style="display:flex;gap:.75rem;flex-shrink:0;align-items:center;">
                    <?php if ($mod['cert_num']): ?>
                        <a href="/actions/download_certificate.php?module_id=<?= $mod['id'] ?>" 
                           class="btn btn-filled" style="padding:.5rem 1rem;font-size:.85rem;display:inline-flex;align-items:center;gap:.35rem;">
                           <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 14l9-5-9-5-9 5 9 5zm0 0l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14zm-4 6v-7.5l4-2.222" /></svg>
                           Certificat
                        </a>
                    <?php endif; ?>
                    <button class="btn btn-secondary" style="padding:.5rem 1rem;font-size:.85rem;"
                        onclick="toggleModuleContent(<?= $mod['id'] ?>)">
                        Voir les cours
                    </button>
                </div>
            </div>
            
            <!-- Barre de progression du module -->
            <div class="progress-wrapper">
                <div class="progress-info">
                    <span style="font-size:.85rem;font-weight:600;">Progression du module</span>
                    <span style="font-size:.85rem;font-weight:700;color:<?= $modProgress >= 100 ? 'var(--color-success)' : 'var(--color-accent)' ?>">
                        <?= $modProgress ?>%
                    </span>
                </div>
                <div class="progress-bar-container">
                    <div class="progress-bar <?= $modProgress >= 100 ? 'success' : '' ?>"
                         style="width:<?= $modProgress ?>%;" data-module-progress-bar="<?= $mod['id'] ?>"></div>
                </div>
            </div>

            <!-- Contenu du module (leçons / cours) -->
            <div id="moduleContent_<?= $mod['id'] ?>" style="display:none;margin-top:1.5rem;">
                <?php if (empty($modLessons)): ?>
                    <p style="color:var(--text-muted);font-size:.85rem;">Aucune leçon disponible dans ce module pour le moment.</p>
                <?php else:
                    // Regrouper les leçons par cours
                    $byCourse = [];
                    foreach ($modLessons as $l) { $byCourse[$l['course_title']][] = $l; }
                    foreach ($byCourse as $ctitle => $cls):
                ?>
                    <div style="margin-bottom:1.5rem;">
                        <h4 style="color:var(--color-accent);font-size:.9rem;margin-bottom:.75rem;border-bottom:1px solid rgba(255,255,255,.05);padding-bottom:.5rem;display:flex;align-items:center;gap:.4rem;">
                            <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" /></svg>
                            <?= htmlspecialchars($ctitle) ?>
                        </h4>
                        <?php foreach ($cls as $l):
                            $lessonPct = 0;
                            if (!empty($l['eval_id']) && isset($attempts[$l['eval_id']])) {
                                $lessonPct = round(floatval($attempts[$l['eval_id']]['best_pct']), 1);
                            } elseif (empty($l['eval_id'])) {
                                $lessonPct = 100;
                            }
                        ?>
                        <div style="border:1px solid rgba(255,255,255,.06);border-radius:10px;padding:1rem;margin-bottom:.75rem;background:rgba(0,0,0,.1);">
                            <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:.75rem;margin-bottom:.75rem;">
                                <div>
                                    <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.3rem;">
                                        <span style="background:rgba(100,255,218,.08);color:var(--color-accent);padding:.15rem .5rem;border-radius:8px;font-size:.72rem;">#<?= $l['position'] ?></span>
                                        <strong style="color:#fff;font-size:.95rem;"><?= htmlspecialchars($l['title']) ?></strong>
                                    </div>
                                    <p style="color:var(--text-secondary);font-size:.8rem;"><?= htmlspecialchars(substr($l['description'] ?? '', 0, 100)) ?></p>
                                </div>
                                <div style="display:flex;gap:.5rem;flex-wrap:wrap;flex-shrink:0;">
                                    <?php if ($l['pdf_path']): ?>
                                        <a href="/<?= htmlspecialchars($l['pdf_path']) ?>" target="_blank" class="btn btn-secondary" style="padding:.35rem .75rem;font-size:.78rem;display:inline-flex;align-items:center;gap:.25rem;">
                                            <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                                            PDF
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($l['video_path']): ?>
                                        <button class="btn btn-secondary" style="padding:.35rem .75rem;font-size:.78rem;display:inline-flex;align-items:center;gap:.25rem;"
                                            onclick="openVideoPlayer('<?= htmlspecialchars($l['video_path']) ?>', '<?= htmlspecialchars(addslashes($l['title'])) ?>')">
                                            <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                            Vidéo
                                        </button>
                                    <?php endif; ?>
                                    <?php if (!empty($l['eval_id']) && !empty($quizData[$l['eval_id']])): ?>
                                        <button class="btn btn-purple" style="padding:.35rem .75rem;font-size:.78rem;display:inline-flex;align-items:center;gap:.25rem;"
                                            onclick="launchQuiz(<?= $l['eval_id'] ?>, <?= $mod['id'] ?>)">
                                            <?php if ($lessonPct > 0): ?>
                                                <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 1121.21 7.89" /></svg>
                                                Retenter
                                            <?php else: ?>
                                                <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" /></svg>
                                                Évaluation
                                            <?php endif; ?>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <!-- Progression leçon -->
                            <div class="progress-wrapper" style="margin-top:.5rem;">
                                <div class="progress-info">
                                    <span style="font-size:.75rem;color:var(--text-secondary);">Progression leçon</span>
                                    <span style="font-size:.78rem;font-weight:600;color:<?= $lessonPct >= 100 ? 'var(--color-success)' : 'var(--text-primary)' ?>">
                                        <?= $lessonPct ?>%
                                    </span>
                                </div>
                                <div class="progress-bar-container" style="height:5px;">
                                    <div class="progress-bar <?= $lessonPct >= 100 ? 'success' : '' ?>" style="width:<?= $lessonPct ?>%;"></div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- ============================================================ -->
<!-- CATALOGUE DES MODULES -->
<!-- ============================================================ -->
<div class="card glass-panel" style="margin-bottom:2rem;">
    <div class="card-header">
        <h2 class="card-title">Catalogue des Modules</h2>
    </div>
    <?php if (empty($allModules)): ?>
        <p style="color:var(--text-secondary);">Aucun module disponible pour le moment.</p>
    <?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1.25rem;">
        <?php foreach ($allModules as $mod):
            $alreadyEnrolled = in_array($mod['id'], $enrolledIds);
        ?>
        <div class="glass-card" style="padding:1.5rem;border-radius:var(--border-radius-md);">
            <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:1rem;">
                <div style="width:44px;height:44px;background:rgba(100,255,218,.1);border-radius:10px;display:flex;align-items:center;justify-content:center;color:var(--color-accent);flex-shrink:0;">
                    <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" /></svg>
                </div>
                <?php if ($alreadyEnrolled): ?>
                    <span class="badge badge-success" style="align-self:center;">Inscrit</span>
                <?php endif; ?>
            </div>
            <h3 style="color:#fff;font-size:1rem;margin-bottom:.5rem;"><?= htmlspecialchars($mod['title']) ?></h3>
            <p style="color:var(--text-secondary);font-size:.82rem;margin-bottom:1rem;line-height:1.6;">
                <?= htmlspecialchars(substr($mod['description'] ?? '', 0, 110)) ?><?= strlen($mod['description'] ?? '') > 110 ? '…' : '' ?>
            </p>
            <?php if (!$alreadyEnrolled): ?>
            <form action="/dashboards/etudiant.php" method="POST">
                <?php csrfInput(); ?>
                <input type="hidden" name="action" value="enroll">
                <input type="hidden" name="module_id" value="<?= $mod['id'] ?>">
                <button type="submit" class="btn btn-primary" style="width:100%;font-size:.85rem;">S'inscrire</button>
            </form>
            <?php else: ?>
            <button class="btn btn-secondary" style="width:100%;font-size:.85rem;" onclick="toggleModuleContent(<?= $mod['id'] ?>)">Voir le contenu</button>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ================================================================
     MODALS NATIFS
     ================================================================ -->
<!-- Dialog QCM -->
<dialog id="evalDialog" style="max-width:650px;">
    <div class="dialog-content" style="max-height:85vh;overflow-y:auto;">
        <div class="dialog-header" style="position:sticky;top:0;background:var(--bg-secondary);z-index:1;padding-bottom:1rem;">
            <h2 class="text-gradient">Évaluation</h2>
            <button class="dialog-close" onclick="this.closest('dialog').close()">&times;</button>
        </div>
        <form id="evalForm" onsubmit="submitEvaluation(event)">
            <?php csrfInput(); ?>
            <div id="evalQuestionsContainer"></div>
            <div style="position:sticky;bottom:0;background:var(--bg-secondary);padding-top:1rem;margin-top:1rem;">
                <button type="submit" class="btn btn-filled" style="width:100%;">Soumettre mes réponses</button>
            </div>
        </form>
    </div>
</dialog>

<!-- Dialog Résultats -->
<dialog id="resultsDialog">
    <div class="dialog-content">
        <div class="dialog-header">
            <h2 class="text-gradient">Résultats</h2>
            <button class="dialog-close" onclick="this.closest('dialog').close()">&times;</button>
        </div>
        <div style="text-align:center;padding:1rem 0;">
            <div style="font-size:2.5rem;margin-bottom:.5rem;" id="resultScore">-</div>
            <div style="color:var(--text-secondary);font-size:.9rem;margin-bottom:1.5rem;">Score obtenu</div>
            <div class="progress-bar-container" style="height:12px;margin-bottom:.5rem;">
                <div class="progress-bar" id="resultProgressBar" style="width:0%;height:100%;border-radius:6px;"></div>
            </div>
            <div style="text-align:right;font-weight:700;font-size:1.1rem;margin-bottom:2rem;" id="resultPct">0%</div>

            <div style="background:rgba(0,0,0,.15);border-radius:10px;padding:1rem;text-align:left;margin-bottom:1.5rem;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem;">
                    <span style="color:var(--text-secondary);font-size:.85rem;">Progression globale du module</span>
                    <span id="resultModPct" style="font-weight:700;color:var(--color-accent);">0%</span>
                </div>
                <div class="progress-bar-container" style="height:8px;">
                    <div class="progress-bar" id="moduleProgressBar" style="width:0%;height:100%;"></div>
                </div>
            </div>

            <div id="certZone" style="display:none;"></div>

            <button class="btn btn-secondary" style="width:100%;margin-top:1rem;" onclick="document.getElementById('resultsDialog').close();location.reload();">Fermer & Actualiser</button>
        </div>
    </div>
</dialog>

<!-- Dialog Lecteur Vidéo -->
<dialog id="videoDialog" style="max-width:750px;width:95%;">
    <div class="dialog-content" style="padding:1.5rem;">
        <div class="dialog-header">
            <h2 id="videoTitle" class="text-gradient" style="font-size:1rem;"></h2>
            <button class="dialog-close" onclick="document.getElementById('videoDialog').close();document.getElementById('videoPlayer').src='';">&times;</button>
        </div>
        <video id="videoPlayer" controls style="width:100%;border-radius:10px;background:#000;margin-top:1rem;"></video>
    </div>
</dialog>

<!-- Script Étudiant -->
<script>
// Données des QCM côté client (injectées par PHP)
const quizData = <?= json_encode($quizData) ?>;

function toggleModuleContent(modId) {
    const el = document.getElementById('moduleContent_' + modId);
    if (!el) return;
    el.style.display = el.style.display === 'none' ? 'block' : 'none';
}

function openVideoPlayer(path, title) {
    document.getElementById('videoTitle').textContent = title;
    document.getElementById('videoPlayer').src = '/' + path;
    document.getElementById('videoDialog').showModal();
}

function launchQuiz(evalId, moduleId) {
    const questions = quizData[evalId];
    if (!questions || questions.length === 0) {
        showToast("Aucune question disponible pour cette évaluation.", 'warning');
        return;
    }
    startEvaluation({
        evalId: evalId,
        moduleId: moduleId,
        questions: questions.map(q => ({
            id: q.id,
            text: q.question_text,
            options: q.options.map(o => ({ id: o.id, text: o.option_text }))
        }))
    });
    // Stocker moduleId sur le form pour le lien certificat
    const form = document.getElementById('evalForm');
    if (form) form.dataset.moduleId = moduleId;
}
</script>

<?php
// Ajouter le script evaluation.js dans le footer uniquement ici
echo '<script src="/assets/js/evaluation.js"></script>';
require_once __DIR__ . '/../includes/footer.php';
?>
