function toggleAllAlcoholAgreements(source) {
                                    const checkboxes = document.querySelectorAll('input[name^="alcohol_agree_"]');
                                    checkboxes.forEach(function(cb) {
                                        if(cb !== source) {
                                            cb.checked = source.checked;
                                        }
                                    });
                                }

                                function goToStep(stepNumber) { const steps = [1, 2, 3]; steps.forEach(function(step) { const content = document.getElementById('step-content-' + step); const stepper = document.getElementById('stepper-' + step); if (content && stepper) { content.classList.toggle('active', step === stepNumber); stepper.classList.toggle('active', step === stepNumber); } }); const currentStep = document.getElementById('current_step'); if (currentStep) { currentStep.value = String(stepNumber); } const card = document.getElementById('mainBorrowLayout'); if (card) { card.scrollIntoView({ behavior: 'smooth', block: 'start' }); } }

function isAlcoholEnabled() {
                                    const checkbox = document.querySelector('input[name="has_alcohol"]');
                                    return checkbox ? checkbox.checked : false;
                                }

                                function toggleAlcoholDetails() {
                                    const alcSection = document.getElementById('alcoholDetailsSection');
                                    if (!alcSection) return;
                                    const show = isAlcoholEnabled();
                                    alcSection.style.display = show ? 'block' : 'none';
                                    alcSection.querySelectorAll('input').forEach(function(el) {
                                        if (show) {
                                            el.removeAttribute('disabled');
                                        } else {
                                            el.setAttribute('disabled', 'disabled');
                                        }
                                    });
                                }

                                function validateAlcoholForm() {
                                    if (!isAlcoholEnabled()) return true;
                                    const checkboxes = document.querySelectorAll('#alcoholDetailsSection input[type="checkbox"]');
                                    let allChecked = true;
                                    checkboxes.forEach(function(cb) { if (!cb.checked) allChecked = false; });
                                    const coordinator = document.getElementById('alcohol_coordinator').value.trim();
                                    const president = document.getElementById('alcohol_president').value.trim();
                                    if (!allChecked) { alert('???????????????????????????????????????????); return false; }
                                    if (!coordinator) { alert('????????????????????????????????); return false; }
                                    if (!president) { alert('???????????????????????????); return false; }
                                    return true;
                                }

                                function isFireEnabled() { const checkbox = document.querySelector('input[name="has_fire"]'); return checkbox ? checkbox.checked : false; }

                                function toggleFireDetails() {
                                    const fireSection = document.getElementById('fireDetailsSection');
                                    if (!fireSection) return;
                                    const show = isFireEnabled();
                                    fireSection.style.display = show ? 'block' : 'none';
                                    fireSection.querySelectorAll('input').forEach(function(el) {
                                        if (show) { el.removeAttribute('disabled'); } else { el.setAttribute('disabled', 'disabled'); }
                                    });
                                }

                                function validateFireForm() { if (!isFireEnabled()) return true; return true; }

                                function isFlagEnabled() { const checkedFlag = document.querySelector('input[name="setup_flags"]:checked'); return checkedFlag && checkedFlag.value === 'yes'; }

                                function addWorkDays(startDate, days) {
                                    const date = new Date(startDate.getFullYear(), startDate.getMonth(), startDate.getDate());
                                    let count = 0;
                                    while (count < days) {
                                        date.setDate(date.getDate() + 1);
                                        const weekDay = date.getDay();
                                        if (weekDay !== 0 && weekDay !== 6) { count++; }
                                    }
                                    return date;
                                }

                                function formatDate(date) { const y = date.getFullYear(); const m = String(date.getMonth() + 1).padStart(2, '0'); const d = String(date.getDate()).padStart(2, '0'); return `${y}-${m}-${d}`; }

                                function getMinFlagDate() { return formatDate(addWorkDays(new Date(), 7)); }

                                function toggleFlagDetails() {
                                    const detailsSection = document.getElementById('flagDetailsSection'); if (!detailsSection) return;
                                    const show = isFlagEnabled(); detailsSection.style.display = show ? 'block' : 'none';
                                    detailsSection.querySelectorAll('input, select, textarea').forEach(function (el) { if (show) { el.removeAttribute('disabled'); } else { el.setAttribute('disabled', 'disabled'); } });
                                    if (show) { syncFlagForm(); validateStartDate(); }
                                }

                                function is30DaysRequired() {
                                    const participantCount = document.getElementById('participant_count')?.value;
                                    const hasAlcohol = document.querySelector('input[name="has_alcohol"]')?.checked;
                                    const hasFire = document.querySelector('input[name="has_fire"]')?.checked;
                                    const hasSales = document.querySelector('input[name="has_sales"]')?.checked;
                                    return (participantCount === '100~200?? || participantCount === '200?????) || hasAlcohol || hasFire || hasSales;
                                }

                                function validateStartDate() {
                                    const startDateInput = document.getElementById('borrow_start_date'); if (!startDateInput || !startDateInput.value) return;
                                    const selectedDate = new Date(startDateInput.value); selectedDate.setHours(0,0,0,0);
                                    const req30 = is30DaysRequired(); const reqFlag = isFlagEnabled(); let errorMsg = '';
                                    if (req30) {
                                        const min30Date = new Date(); min30Date.setDate(min30Date.getDate() + 30); min30Date.setHours(0,0,0,0);
                                        if (selectedDate < min30Date) { errorMsg = '??????????????????????????????????????????100????????? 30 ?????????\n?????????????????????????????? ' + formatDate(min30Date) + ' ???????; }
                                    }
                                    if (!errorMsg && reqFlag) {
                                        const minFlagDateStr = getMinFlagDate(); const minFlagDate = new Date(minFlagDateStr); minFlagDate.setHours(0,0,0,0);
                                        if (selectedDate < minFlagDate) { errorMsg = '???????????????????????? 7 ????????????????????' + minFlagDateStr + '???\n?????????????????????????????????????; }
                                    }
                                    if (errorMsg) { alert(errorMsg); startDateInput.value = ''; const sEl = document.getElementById('flag_start_date'); const eEl = document.getElementById('flag_end_date'); if (sEl) sEl.value = ''; if (eEl) eEl.value = ''; }
                                }

                                function syncFlagForm() {
                                    if (!isFlagEnabled()) return; const flagCount = document.getElementById('flag_count'); if (flagCount && flagCount.value !== '' && Number(flagCount.value) > 20) { flagCount.value = 20; }
                                    const bs = document.getEleme
