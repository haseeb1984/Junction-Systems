'use strict';
import { config, apiRequest, ApiError } from '../lib/api-client.mjs';
import { find, mustFind, disableButton, enableButton, showMessage, clearMessages } from '../lib/dom.mjs';
import { bus } from '../lib/event-bus.mjs';

let _submitInFlight = false;
const state = { phone:'', isVerified:false, resendTimer:null, isDigital:false };

export function init() {
    const codSection = find('[data-cod-section]');
    if (!codSection) return;
    const placeOrderBtn = find('[data-place-order]') || find('#place_order') || find('button[name="woocommerce_checkout_place_order"]');
    document.querySelectorAll('input[name="payment_method"]').forEach(input => {
        input.addEventListener('change', () => handlePaymentChange(input.value, codSection, placeOrderBtn));
    });
    const checked = document.querySelector('input[name="payment_method"]:checked');
    if (checked) handlePaymentChange(checked.value, codSection, placeOrderBtn);
    mustFind('[data-cod-send-btn]', codSection).addEventListener('click', () => handleSendOtp(codSection, placeOrderBtn));
    mustFind('[data-cod-verify-btn]', codSection).addEventListener('click', () => handleVerifyOtp(codSection, placeOrderBtn));
    mustFind('[data-cod-resend-btn]', codSection).addEventListener('click', () => { resetOtpForm(codSection); handleSendOtp(codSection, placeOrderBtn); });
    const billingPhoneInput = find('#billing_phone');
    const codPhoneInput = mustFind('[data-cod-phone]', codSection);
    if (billingPhoneInput && !codPhoneInput.value) codPhoneInput.value = billingPhoneInput.value;
    const otpInput = mustFind('[data-cod-otp-input]', codSection);
    otpInput.addEventListener('input', () => {
        otpInput.value = otpInput.value.replace(/\D/g,'').slice(0,6);
        if (otpInput.value.length===6 && !_submitInFlight) handleVerifyOtp(codSection, placeOrderBtn);
    });
    const form = find('form.woocommerce-checkout') || find('form[name="checkout"]');
    if (form && placeOrderBtn) {
        form.addEventListener('submit', e => {
            const pm = document.querySelector('input[name="payment_method"]:checked')?.value;
            if (pm==='cod' && !state.isVerified && !state.isDigital) {
                e.preventDefault(); e.stopImmediatePropagation();
                showMessage(codSection,'براہ کرم پہلے اپنا فون نمبر تصدیق کریں۔ Please verify your phone first.','error');
            }
        });
    }
}

function handlePaymentChange(method, codSection, placeOrderBtn) {
    const isCod = method==='cod';
    codSection.setAttribute('aria-hidden', String(!isCod));
    codSection.classList.toggle('pk-cod--visible', isCod);
    if (placeOrderBtn) placeOrderBtn.disabled = isCod && !state.isVerified && !state.isDigital;
}

async function handleSendOtp(codSection, placeOrderBtn) {
    clearMessages(codSection);
    const phoneInput = mustFind('[data-cod-phone]', codSection);
    const rawPhone   = phoneInput.value.trim();
    if (!rawPhone) { showMessage(codSection,'براہ کرم موبائل نمبر درج کریں۔ Please enter your mobile number.','error'); phoneInput.focus(); return; }
    const billingPhoneInput = find('#billing_phone');
    if (billingPhoneInput) {
        billingPhoneInput.value = rawPhone;
        billingPhoneInput.dispatchEvent(new Event('input', { bubbles: true }));
        billingPhoneInput.dispatchEvent(new Event('change', { bubbles: true }));
    }
    const sendBtn = mustFind('[data-cod-send-btn]', codSection);
    disableButton(sendBtn,'بھیج رہے ہیں...');
    try {
        const res = await apiRequest('/cod/send-otp', { nonce: config.codNonce, body: { phone: rawPhone } });
        // Server signals digital order — skip OTP entirely
        if (res.skip === true) {
            state.isDigital = true; state.isVerified = true;
            showSuccess(codSection, placeOrderBtn); return;
        }
        state.phone = rawPhone;
        showOtpForm(codSection); startCountdown(codSection);
        bus.emit('cod:otp-sent', { phone: rawPhone });
    } catch(e) {
        if (e instanceof ApiError) {
            showMessage(codSection, e.isRateLimited ? `بہت زیادہ کوششیں۔ ${e.message}` : (e.message || 'OTP نہیں بھیجا جا سکا۔ Please try again.'),'error');
        }
        enableButton(sendBtn);
    }
}

async function handleVerifyOtp(codSection, placeOrderBtn) {
    if (_submitInFlight) return;
    if (state.isDigital) { showSuccess(codSection, placeOrderBtn); return; }
    _submitInFlight = true;
    clearMessages(codSection);
    const otpInput  = mustFind('[data-cod-otp-input]', codSection);
    const code      = otpInput.value.trim();
    if (code.length!==6) { showMessage(codSection,'6 ہندسوں کا کوڈ درج کریں۔','error'); otpInput.focus(); _submitInFlight=false; return; }
    const verifyBtn = mustFind('[data-cod-verify-btn]', codSection);
    disableButton(verifyBtn,'تصدیق ہو رہی ہے...');
    try {
        await apiRequest('/cod/verify-otp', { nonce: config.codNonce, body: { phone: state.phone, code } });
        state.isVerified = true; clearCountdown(); showSuccess(codSection, placeOrderBtn);
        bus.emit('cod:verified', { phone: state.phone });
    } catch(e) {
        enableButton(verifyBtn);
        if (e instanceof ApiError) {
            if      (e.code==='otp_expired')     { showMessage(codSection,'کوڈ کی میعاد ختم۔ Resend a new code.','error'); lockOtpForm(codSection); }
            else if (e.code==='replay_rejected')  { state.isVerified=true; showSuccess(codSection, placeOrderBtn); }
            else if (e.code==='invalid_otp') {
                otpInput.value=''; otpInput.focus();
                const rem = parseInt(e.message.match(/\d+/)?.[0]||'0',10);
                showMessage(codSection, rem>0 ? `غلط کوڈ۔ ${rem} کوشش باقی۔` : 'تمام کوششیں ختم۔ نیا کوڈ منگوائیں۔','error');
                if (rem===0) lockOtpForm(codSection);
            } else showMessage(codSection,'تصدیق ناکام۔ Please try again.','error');
        }
        bus.emit('cod:failed', { reason: e.message });
    } finally { _submitInFlight = false; }
}

function showOtpForm(s) {
    mustFind('[data-cod-phone-section]',s).setAttribute('aria-hidden','true');
    const f=mustFind('[data-cod-otp-form]',s); f.setAttribute('aria-hidden','false'); f.classList.add('pk-cod__section--visible');
    mustFind('[data-cod-otp-input]',s).focus();
}
function showSuccess(s, btn) {
    ['[data-cod-otp-form]','[data-cod-phone-section]'].forEach(sel=>{ try{find(sel,s)?.setAttribute('aria-hidden','true');}catch(_){} });
    const ok=mustFind('[data-cod-success]',s); ok.setAttribute('aria-hidden','false');
    if (btn) { btn.disabled=false; btn.focus(); }
}
function lockOtpForm(s) {
    mustFind('[data-cod-otp-input]',s).disabled=true;
    mustFind('[data-cod-verify-btn]',s).disabled=true;
    mustFind('[data-cod-resend-btn]',s).disabled=false;
}
function resetOtpForm(s) {
    state.isVerified=false; state.isDigital=false;
    mustFind('[data-cod-otp-input]',s).value='';
    mustFind('[data-cod-otp-input]',s).disabled=false;
    enableButton(mustFind('[data-cod-verify-btn]',s));
    clearMessages(s);
}
function startCountdown(s) {
    const btn=mustFind('[data-cod-resend-btn]',s), timer=mustFind('[data-cod-timer]',s);
    btn.disabled=true; let secs=60;
    state.resendTimer=setInterval(()=>{ secs--; timer.textContent=`(${secs}s)`; if(secs<=0){clearCountdown();btn.disabled=false;timer.textContent='';} },1000);
}
function clearCountdown() { if(state.resendTimer){clearInterval(state.resendTimer);state.resendTimer=null;} }
