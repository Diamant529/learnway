/**
 * Learn Way - Logique interactive des évaluations QCM (côté étudiant)
 */

/**
 * Lance un QCM dans le dialog natif d'évaluation
 * @param {Object} config  { evalId, moduleId, questions: [{id, text, options:[{id,text}]}] }
 */
function startEvaluation(config) {
    const dialog = document.getElementById('evalDialog');
    const form   = document.getElementById('evalForm');
    if (!dialog || !form) return;

    form.dataset.evalId   = config.evalId;
    form.dataset.moduleId = config.moduleId;

    const container = document.getElementById('evalQuestionsContainer');
    container.innerHTML = '';

    config.questions.forEach((q, qi) => {
        const block = document.createElement('div');
        block.className = 'eval-question';
        block.style.cssText = 'margin-bottom:1.5rem;padding:1.25rem;background:rgba(0,0,0,.15);border-radius:10px;';

        const title = document.createElement('p');
        title.style.cssText = 'color:#fff;font-weight:600;margin-bottom:1rem;';
        title.innerHTML = `<span style="color:var(--color-accent);margin-right:.5rem;">${qi + 1}.</span>${q.text}`;
        block.appendChild(title);

        q.options.forEach(opt => {
            const label = document.createElement('label');
            label.style.cssText = `
                display:flex;align-items:center;gap:.75rem;padding:.7rem 1rem;
                border-radius:8px;cursor:pointer;transition:.2s;margin-bottom:.5rem;
                border:1px solid rgba(255,255,255,.07);background:rgba(255,255,255,.03);
            `;
            label.addEventListener('mouseenter', () => { label.style.borderColor = 'rgba(100,255,218,.3)'; });
            label.addEventListener('mouseleave', () => {
                if (!label.querySelector('input').checked) label.style.borderColor = 'rgba(255,255,255,.07)';
            });

            const radio = document.createElement('input');
            radio.type  = 'radio';
            radio.name  = `q_${q.id}`;
            radio.value = opt.id;
            radio.dataset.questionId = q.id;
            radio.style.accentColor  = 'var(--color-accent)';
            radio.addEventListener('change', () => {
                // Réinitialiser tous les labels de ce groupe
                document.querySelectorAll(`input[name="q_${q.id}"]`).forEach(r => {
                    r.closest('label').style.borderColor = 'rgba(255,255,255,.07)';
                    r.closest('label').style.background  = 'rgba(255,255,255,.03)';
                });
                label.style.borderColor = 'rgba(100,255,218,.5)';
                label.style.background  = 'rgba(100,255,218,.07)';
            });

            const span = document.createElement('span');
            span.style.color = 'var(--text-secondary)';
            span.textContent = opt.text;

            label.appendChild(radio);
            label.appendChild(span);
            block.appendChild(label);
        });

        container.appendChild(block);
    });

    dialog.showModal();
}

/**
 * Soumet les réponses du QCM via AJAX et affiche les résultats
 */
async function submitEvaluation(event) {
    event.preventDefault();
    const form      = event.target;
    const evalId    = parseInt(form.dataset.evalId);
    const moduleId  = parseInt(form.dataset.moduleId);
    const submitBtn = form.querySelector('[type="submit"]');

    // Collecter les réponses
    const radios = form.querySelectorAll('input[type="radio"]:checked');
    const answers = {};
    let allAnswered = true;

    // Collecter les groupes de questions
    const groups = new Set();
    form.querySelectorAll('input[type="radio"]').forEach(r => groups.add(r.name));

    groups.forEach(name => {
        const checked = form.querySelector(`input[name="${name}"]:checked`);
        if (!checked) { allAnswered = false; return; }
        const qid = checked.dataset.questionId;
        answers[qid] = parseInt(checked.value);
    });

    if (!allAnswered) {
        showToast('Veuillez répondre à toutes les questions.', 'warning');
        return;
    }

    submitBtn.disabled = true;
    submitBtn.textContent = 'Correction en cours…';

    try {
        const result = await AJAX.post('/api/submit_evaluation.php', {
            evaluation_id: evalId,
            module_id: moduleId,
            answers: answers
        });

        // Fermer le dialog QCM
        document.getElementById('evalDialog').close();

        // Afficher les résultats
        showResults(result);

    } catch (err) {
        showToast(err.message || 'Erreur lors de la soumission.', 'danger');
        submitBtn.disabled = false;
        submitBtn.textContent = 'Soumettre mes réponses';
    }
}

/**
 * Affiche les résultats dans le dialog de résultat
 */
function showResults(result) {
    const dialog = document.getElementById('resultsDialog');
    if (!dialog) return;

    const pct   = result.percentage;
    const score = result.score_obtained;
    const max   = result.max_score;

    document.getElementById('resultScore').textContent    = `${score} / ${max}`;
    document.getElementById('resultPct').textContent      = `${pct}%`;
    document.getElementById('resultModPct').textContent   = `${result.module_progress}%`;

    const bar = document.getElementById('resultProgressBar');
    bar.style.width = `${pct}%`;
    bar.className   = 'progress-bar' + (pct >= 80 ? ' success' : '');

    const modBar = document.getElementById('moduleProgressBar');
    if (modBar) {
        modBar.style.width = `${result.module_progress}%`;
        modBar.className   = 'progress-bar' + (result.module_progress >= 100 ? ' success' : '');
    }

    // Zone certificat
    const certZone = document.getElementById('certZone');
    if (certZone) {
        if (result.module_progress >= 100) {
            certZone.style.display = 'block';
            if (result.certificate_generated) {
                certZone.innerHTML = `
                    <div class="alert alert-success glass-panel" style="text-align:center;">
                        <strong>Félicitations !</strong> Vous avez complété ce module à 100% !<br>
                        Votre certificat <strong>#${result.certificate_number}</strong> a été émis.<br>
                        <a href="/actions/download_certificate.php?module_id=${document.getElementById('evalForm')?.dataset?.moduleId || ''}" 
                           class="btn btn-filled" style="margin-top:1rem;display:inline-flex;align-items:center;justify-content:center;gap:0.35rem;">
                           <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
                           Télécharger mon certificat
                        </a>
                    </div>`;
            } else {
                certZone.innerHTML = `
                    <div class="alert alert-info glass-panel" style="text-align:center;background:rgba(59,130,246,.1);color:var(--color-info);border-left-color:var(--color-info);">
                        Votre certificat #${result.certificate_number} est disponible.<br>
                        <a href="/actions/download_certificate.php?module_id=${document.getElementById('evalForm')?.dataset?.moduleId || ''}" 
                           class="btn btn-primary" style="margin-top:1rem;display:inline-flex;align-items:center;justify-content:center;gap:0.35rem;">
                           <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>
                           Télécharger mon certificat
                        </a>
                    </div>`;
            }
        } else {
            certZone.style.display = 'none';
        }
    }

    // Mettre à jour la barre de progression globale du module dans la page parente
    const moduleProgressInPage = document.querySelector(`[data-module-progress-bar]`);
    if (moduleProgressInPage) {
        moduleProgressInPage.style.width = `${result.module_progress}%`;
    }

    dialog.showModal();
}

window.startEvaluation = startEvaluation;
window.submitEvaluation = submitEvaluation;
