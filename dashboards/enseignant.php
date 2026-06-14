<?php
/**
 * Learn Way - Tableau de bord de l'Enseignant
 */
$pageTitle = "Espace Enseignant";
require_once __DIR__ . '/../includes/header.php';
requireRole('enseignant');

$successMsg = $_SESSION['action_success'] ?? null;
$errorMsg   = $_SESSION['action_error'] ?? null;
unset($_SESSION['action_success'], $_SESSION['action_error']);

$teacherId = $_SESSION['user_id'];

// Modules affectés à cet enseignant
$stmtMod = $pdo->prepare('SELECT m.* FROM modules m JOIN module_teachers mt ON m.id = mt.module_id WHERE mt.teacher_id = :tid ORDER BY m.created_at DESC');
$stmtMod->execute(['tid' => $teacherId]);
$modules = $stmtMod->fetchAll();

// Cours créés par cet enseignant (avec leur module)
$stmtCourses = $pdo->prepare('
    SELECT c.*, m.title as module_title
    FROM courses c JOIN modules m ON c.module_id = m.id
    WHERE c.created_by = :uid ORDER BY m.title, c.created_at DESC
');
$stmtCourses->execute(['uid' => $teacherId]);
$courses = $stmtCourses->fetchAll();

// Leçons (avec évaluations) pour toutes les courses de cet enseignant
$stmtLessons = $pdo->prepare('
    SELECT l.*, c.title as course_title, e.id as eval_id, e.title as eval_title
    FROM lessons l
    JOIN courses c ON l.course_id = c.id
    LEFT JOIN evaluations e ON l.id = e.lesson_id
    WHERE c.created_by = :uid ORDER BY c.title, l.position
');
$stmtLessons->execute(['uid' => $teacherId]);
$lessons = $stmtLessons->fetchAll();

// Questions pour toutes les évaluations de cet enseignant
$evalIds = array_filter(array_column($lessons, 'eval_id'));
$questions = [];
if (!empty($evalIds)) {
    $in = implode(',', array_map('intval', $evalIds));
    $stmtQ = $pdo->query("SELECT q.*, GROUP_CONCAT(CONCAT(o.id,'|',o.option_text,'|',o.is_correct) SEPARATOR ';;') as options_raw FROM questions q LEFT JOIN options o ON o.question_id = q.id WHERE q.evaluation_id IN ($in) GROUP BY q.id ORDER BY q.evaluation_id");
    foreach ($stmtQ->fetchAll() as $row) {
        $opts = [];
        if ($row['options_raw']) {
            foreach (explode(';;', $row['options_raw']) as $part) {
                [$oid, $otext, $ocorrect] = explode('|', $part, 3);
                $opts[] = ['id' => $oid, 'text' => $otext, 'is_correct' => $ocorrect];
            }
        }
        $row['options'] = $opts;
        $questions[$row['evaluation_id']][] = $row;
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
    <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
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
            <span class="stat-number"><?= count($modules) ?></span>
            <span class="stat-label">Modules affectés</span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon warning">
            <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
        </div>
        <div class="stat-details">
            <span class="stat-number"><?= count($courses) ?></span>
            <span class="stat-label">Cours créés</span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon success">
            <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
        </div>
        <div class="stat-details">
            <span class="stat-number"><?= count($lessons) ?></span>
            <span class="stat-label">Leçons créées</span>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- SECTION: COURS -->
<!-- ============================================================ -->
<div class="card glass-panel" style="margin-bottom: 2rem;">
    <div class="card-header">
        <h2 class="card-title">Mes Cours</h2>
        <?php if (!empty($modules)): ?>
        <button class="btn btn-primary" onclick="document.getElementById('createCourseDialog').showModal()">+ Créer un Cours</button>
        <?php else: ?>
        <span style="color: var(--color-warning); font-size: 0.85rem; display: inline-flex; align-items: center; gap: .35rem;">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
            Aucun module ne vous est affecté. Contactez le promoteur.
        </span>
        <?php endif; ?>
    </div>
    <?php if (empty($courses)): ?>
        <p style="color: var(--text-secondary); padding: 1rem 0;">Vous n'avez pas encore créé de cours.</p>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table-custom">
            <thead><tr><th>Titre</th><th>Module</th><th>Description</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($courses as $c): ?>
                <tr>
                    <td style="font-weight:600;color:#fff;"><?= htmlspecialchars($c['title']) ?></td>
                    <td><?= htmlspecialchars($c['module_title']) ?></td>
                    <td><?= htmlspecialchars(substr($c['description'] ?? '', 0, 70)) ?><?= strlen($c['description'] ?? '') > 70 ? '…' : '' ?></td>
                    <td>
                        <div style="display:flex;gap:.5rem;">
                            <button class="btn btn-secondary" style="padding:.35rem .7rem;font-size:.8rem;"
                                onclick="openEditCourse(<?= $c['id'] ?>, '<?= htmlspecialchars(addslashes($c['title'])) ?>', '<?= htmlspecialchars(addslashes($c['description'] ?? '')) ?>')">Modifier</button>
                            <form action="/actions/manage_courses.php" method="POST" onsubmit="return confirm('Supprimer ce cours et toutes ses leçons ?')">
                                <?php csrfInput(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="course_id" value="<?= $c['id'] ?>">
                                <button type="submit" class="btn btn-danger" style="padding:.35rem .7rem;font-size:.8rem;">Supprimer</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ============================================================ -->
<!-- SECTION: LEÇONS & QCM (organisé par cours) -->
<!-- ============================================================ -->
<?php if (!empty($courses)): ?>
<div class="card glass-panel" style="margin-bottom: 2rem;">
    <div class="card-header">
        <h2 class="card-title">Leçons & Évaluations</h2>
        <button class="btn btn-primary" onclick="document.getElementById('createLessonDialog').showModal()">+ Ajouter une Leçon</button>
    </div>

    <?php if (empty($lessons)): ?>
        <p style="color: var(--text-secondary); padding: 1rem 0;">Aucune leçon créée. Commencez par ajouter des leçons à vos cours.</p>
    <?php else: ?>
        <?php
        // Regrouper les leçons par course_title
        $lessonsByCourse = [];
        foreach ($lessons as $l) { $lessonsByCourse[$l['course_title']][] = $l; }
        ?>
        <?php foreach ($lessonsByCourse as $courseTitle => $courseLessons): ?>
        <div style="margin-bottom: 2rem;">
            <h3 style="color: var(--color-accent); font-size:1rem; margin-bottom:1rem; padding-bottom:.5rem; border-bottom: 1px solid rgba(255,255,255,0.06); display: flex; align-items: center; gap: .4rem;">
                <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" /></svg>
                <?= htmlspecialchars($courseTitle) ?>
            </h3>
            <?php foreach ($courseLessons as $l): ?>
            <div class="glass-card" style="padding: 1.25rem; margin-bottom: 1rem; border-radius: var(--border-radius-sm);">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;">
                    <div style="flex:1;">
                        <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:.5rem;">
                            <span style="background:rgba(100,255,218,.1);color:var(--color-accent);padding:.2rem .6rem;border-radius:12px;font-size:.75rem;font-weight:600;">Leçon <?= $l['position'] ?></span>
                            <strong style="color:#fff;"><?= htmlspecialchars($l['title']) ?></strong>
                        </div>
                        <p style="color:var(--text-secondary);font-size:.85rem;margin-bottom:.75rem;"><?= htmlspecialchars($l['description'] ?? '') ?></p>
                        <div style="display:flex;gap:.75rem;flex-wrap:wrap;">
                            <?php if ($l['pdf_path']): ?>
                                <span class="badge badge-info">PDF joint</span>
                            <?php endif; ?>
                            <?php if ($l['video_path']): ?>
                                <span class="badge badge-info">Vidéo jointe</span>
                            <?php endif; ?>
                            <?php if ($l['eval_id']): ?>
                                <span class="badge badge-success">QCM créé</span>
                            <?php else: ?>
                                <span class="badge badge-warning">Pas de QCM</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
                        <?php if (!$l['eval_id']): ?>
                        <button class="btn btn-purple" style="padding:.4rem .8rem;font-size:.8rem;"
                            onclick="openCreateEval(<?= $l['id'] ?>)">+ Créer QCM</button>
                        <?php else: ?>
                        <button class="btn btn-secondary" style="padding:.4rem .8rem;font-size:.8rem;"
                            onclick="openAddQuestion(<?= $l['eval_id'] ?>, '<?= htmlspecialchars(addslashes($l['eval_title'])) ?>')">+ Question</button>
                        <?php endif; ?>
                        <form action="/actions/manage_lessons.php" method="POST" onsubmit="return confirm('Supprimer cette leçon ?')">
                            <?php csrfInput(); ?>
                            <input type="hidden" name="action" value="delete_lesson">
                            <input type="hidden" name="lesson_id" value="<?= $l['id'] ?>">
                            <button type="submit" class="btn btn-danger" style="padding:.4rem .8rem;font-size:.8rem;">Supprimer</button>
                        </form>
                    </div>
                </div>

                <!-- Questions du QCM -->
                <?php if ($l['eval_id'] && !empty($questions[$l['eval_id']])): ?>
                <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid rgba(255,255,255,0.06);">
                    <p style="color:var(--text-secondary);font-size:.8rem;font-weight:600;margin-bottom:.75rem;">Questions de l'évaluation "<?= htmlspecialchars($l['eval_title']) ?>" :</p>
                    <?php foreach ($questions[$l['eval_id']] as $q): ?>
                    <div style="background:rgba(0,0,0,.15);border-radius:8px;padding:.9rem 1rem;margin-bottom:.75rem;">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.6rem;">
                            <span style="color:#fff;font-size:.9rem;"><?= htmlspecialchars($q['question_text']) ?></span>
                            <div style="display:flex;align-items:center;gap:.75rem;flex-shrink:0;">
                                <span class="badge badge-info"><?= $q['points'] ?> pt<?= $q['points'] > 1 ? 's' : '' ?></span>
                                <form action="/actions/manage_lessons.php" method="POST" style="display:inline;">
                                    <?php csrfInput(); ?>
                                    <input type="hidden" name="action" value="delete_question">
                                    <input type="hidden" name="question_id" value="<?= $q['id'] ?>">
                                    <button type="submit" class="btn btn-danger" style="padding:.2rem .5rem;font-size:.75rem;" onclick="return confirm('Supprimer cette question ?')">✕</button>
                                </form>
                            </div>
                        </div>
                        <div style="display:flex;flex-wrap:wrap;gap:.5rem;">
                        <?php foreach ($q['options'] as $o): ?>
                            <span style="padding:.2rem .6rem;border-radius:8px;font-size:.78rem;<?= $o['is_correct'] ? 'background:rgba(16,185,129,.2);color:var(--color-success);border:1px solid rgba(16,185,129,.3);' : 'background:rgba(255,255,255,.05);color:var(--text-secondary);' ?>">
                                <?= $o['is_correct'] ? '✓ ' : '' ?><?= htmlspecialchars($o['text']) ?>
                            </span>
                        <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ================================================================
     MODALS NATIFS
     ================================================================ -->

<!-- Créer un Cours -->
<dialog id="createCourseDialog">
    <div class="dialog-content">
        <div class="dialog-header">
            <h2 class="text-gradient">Nouveau Cours</h2>
            <button class="dialog-close" onclick="this.closest('dialog').close()">&times;</button>
        </div>
        <form action="/actions/manage_courses.php" method="POST">
            <?php csrfInput(); ?>
            <input type="hidden" name="action" value="create">
            <div class="form-group">
                <label class="form-label">Module associé</label>
                <select name="module_id" class="form-control glass-input" required>
                    <option value="">Sélectionnez un module…</option>
                    <?php foreach ($modules as $m): ?>
                        <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Titre du cours</label>
                <input type="text" name="title" class="form-control glass-input" required placeholder="Ex: Introduction au HTML5">
            </div>
            <div class="form-group">
                <label class="form-label">Description</label>
                <textarea name="description" class="form-control glass-input" rows="3" placeholder="Décrivez le cours…"></textarea>
            </div>
            <div style="display:flex;gap:1rem;justify-content:flex-end;margin-top:1.5rem;">
                <button type="button" class="btn btn-secondary" onclick="this.closest('dialog').close()">Annuler</button>
                <button type="submit" class="btn btn-filled">Créer</button>
            </div>
        </form>
    </div>
</dialog>

<!-- Modifier un Cours -->
<dialog id="editCourseDialog">
    <div class="dialog-content">
        <div class="dialog-header">
            <h2 class="text-gradient">Modifier le Cours</h2>
            <button class="dialog-close" onclick="this.closest('dialog').close()">&times;</button>
        </div>
        <form action="/actions/manage_courses.php" method="POST">
            <?php csrfInput(); ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="course_id" id="editCourseId">
            <div class="form-group">
                <label class="form-label">Titre</label>
                <input type="text" name="title" id="editCourseTitle" class="form-control glass-input" required>
            </div>
            <div class="form-group">
                <label class="form-label">Description</label>
                <textarea name="description" id="editCourseDesc" class="form-control glass-input" rows="3"></textarea>
            </div>
            <div style="display:flex;gap:1rem;justify-content:flex-end;margin-top:1.5rem;">
                <button type="button" class="btn btn-secondary" onclick="this.closest('dialog').close()">Annuler</button>
                <button type="submit" class="btn btn-filled">Enregistrer</button>
            </div>
        </form>
    </div>
</dialog>

<!-- Ajouter une Leçon -->
<dialog id="createLessonDialog">
    <div class="dialog-content">
        <div class="dialog-header">
            <h2 class="text-gradient">Nouvelle Leçon</h2>
            <button class="dialog-close" onclick="this.closest('dialog').close()">&times;</button>
        </div>
        <form action="/actions/manage_lessons.php" method="POST" enctype="multipart/form-data">
            <?php csrfInput(); ?>
            <input type="hidden" name="action" value="create_lesson">
            <div class="form-group">
                <label class="form-label">Cours associé</label>
                <select name="course_id" class="form-control glass-input" required>
                    <option value="">Sélectionnez un cours…</option>
                    <?php foreach ($courses as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['module_title'] . ' › ' . $c['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Titre de la leçon</label>
                <input type="text" name="lesson_title" class="form-control glass-input" required placeholder="Ex: Introduction aux balises HTML5">
            </div>
            <div class="form-group">
                <label class="form-label">Description</label>
                <textarea name="lesson_description" class="form-control glass-input" rows="2" placeholder="Contenu abordé dans cette leçon…"></textarea>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                <div class="form-group">
                    <label class="form-label">Document PDF <small style="color:var(--text-muted)">(max 10 Mo)</small></label>
                    <input type="file" name="pdf_file" class="form-control glass-input" accept=".pdf">
                </div>
                <div class="form-group">
                    <label class="form-label">Vidéo MP4 <small style="color:var(--text-muted)">(max 50 Mo)</small></label>
                    <input type="file" name="video_file" class="form-control glass-input" accept=".mp4,.webm">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Position dans le cours</label>
                <input type="number" name="position" class="form-control glass-input" value="1" min="1">
            </div>
            <div style="display:flex;gap:1rem;justify-content:flex-end;margin-top:1.5rem;">
                <button type="button" class="btn btn-secondary" onclick="this.closest('dialog').close()">Annuler</button>
                <button type="submit" class="btn btn-filled">Ajouter</button>
            </div>
        </form>
    </div>
</dialog>

<!-- Créer un QCM -->
<dialog id="createEvalDialog">
    <div class="dialog-content">
        <div class="dialog-header">
            <h2 class="text-gradient">Créer une Évaluation (QCM)</h2>
            <button class="dialog-close" onclick="this.closest('dialog').close()">&times;</button>
        </div>
        <form action="/actions/manage_lessons.php" method="POST">
            <?php csrfInput(); ?>
            <input type="hidden" name="action" value="create_evaluation">
            <input type="hidden" name="lesson_id" id="createEvalLessonId">
            <div class="form-group">
                <label class="form-label">Titre de l'évaluation</label>
                <input type="text" name="eval_title" class="form-control glass-input" required placeholder="Ex: Évaluation – HTML sémantique">
            </div>
            <div style="display:flex;gap:1rem;justify-content:flex-end;margin-top:1.5rem;">
                <button type="button" class="btn btn-secondary" onclick="this.closest('dialog').close()">Annuler</button>
                <button type="submit" class="btn btn-filled">Créer</button>
            </div>
        </form>
    </div>
</dialog>

<!-- Ajouter une Question au QCM -->
<dialog id="addQuestionDialog" style="max-width:620px;">
    <div class="dialog-content">
        <div class="dialog-header">
            <div>
                <h2 class="text-gradient">Ajouter une Question</h2>
                <p id="addQuestionEvalTitle" style="color:var(--text-secondary);font-size:.85rem;margin-top:.25rem;"></p>
            </div>
            <button class="dialog-close" onclick="this.closest('dialog').close()">&times;</button>
        </div>
        <form action="/actions/manage_lessons.php" method="POST">
            <?php csrfInput(); ?>
            <input type="hidden" name="action" value="add_question">
            <input type="hidden" name="evaluation_id" id="addQuestionEvalId">
            <div class="form-group">
                <label class="form-label">Texte de la question</label>
                <textarea name="question_text" class="form-control glass-input" rows="2" required placeholder="Formulez votre question ici…"></textarea>
            </div>
            <div class="form-group">
                <label class="form-label">Points</label>
                <input type="number" name="points" class="form-control glass-input" value="5" min="1">
            </div>
            <div class="form-group">
                <label class="form-label">Options de réponse <small style="color:var(--text-muted)">(min. 2, sélectionnez la bonne réponse)</small></label>
                <div id="optionsContainer">
                    <?php for ($i = 0; $i < 4; $i++): ?>
                    <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:.6rem;">
                        <input type="radio" name="correct_index" value="<?= $i ?>" style="accent-color:var(--color-accent);width:16px;height:16px;" title="Bonne réponse">
                        <input type="text" name="options[]" class="form-control glass-input" style="margin:0;" placeholder="Option <?= $i + 1 ?>">
                    </div>
                    <?php endfor; ?>
                </div>
            </div>
            <div style="display:flex;gap:1rem;justify-content:flex-end;margin-top:1.5rem;">
                <button type="button" class="btn btn-secondary" onclick="this.closest('dialog').close()">Annuler</button>
                <button type="submit" class="btn btn-filled">Ajouter la question</button>
            </div>
        </form>
    </div>
</dialog>

<script>
function openEditCourse(id, title, desc) {
    document.getElementById('editCourseId').value = id;
    document.getElementById('editCourseTitle').value = title;
    document.getElementById('editCourseDesc').value = desc;
    document.getElementById('editCourseDialog').showModal();
}
function openCreateEval(lessonId) {
    document.getElementById('createEvalLessonId').value = lessonId;
    document.getElementById('createEvalDialog').showModal();
}
function openAddQuestion(evalId, evalTitle) {
    document.getElementById('addQuestionEvalId').value = evalId;
    document.getElementById('addQuestionEvalTitle').textContent = 'Évaluation : ' + evalTitle;
    document.getElementById('addQuestionDialog').showModal();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
