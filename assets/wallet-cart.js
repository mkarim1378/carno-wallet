/**
 * جاوا اسکریپت: مدیریت کیف پول در سبد خرید
 */

jQuery(function($) {
    'use strict';

    const $walletBtn = $('#carno-wallet-toggle-btn');
    const $cartForm = $('form[name="post"]');

    if (!$walletBtn.length) return;

    // رویداد کلیک بر روی دکمه
    $walletBtn.on('click', function(e) {
        e.preventDefault();

        const $btn = $(this);
        const isCurrentlyActive = $btn.text().includes('لغو');
        const text = isCurrentlyActive ? 'استفاده از کیف پول' : 'لغو استفاده از کیف پول';

        $btn.prop('disabled', true).text('در حال پردازش...');

        const action = isCurrentlyActive ? 'carno_remove_wallet_credit' : 'carno_apply_wallet_credit';

        $.ajax({
            type: 'POST',
            url: carnoWallet.ajax_url,
            data: {
                action: action,
                nonce: carnoWallet.nonce,
            },
            success: function(response) {
                if (response.success) {
                    $btn.text(text);
                    
                    // بروزرسانی سبد خرید
                    $('body').trigger('update_checkout');

                    // نمایش پیام
                    const message = isCurrentlyActive ? 
                        'استفاده از کیف پول لغو شد.' : 
                        'کیف پول فعال شد.';
                    
                    showNotice(message, 'success');
                } else {
                    showNotice(response.data.message || 'خطایی رخ داد.', 'error');
                    $btn.text(text);
                }
            },
            error: function() {
                showNotice('خطا در برقراری ارتباط.', 'error');
                $btn.text(text);
            },
            complete: function() {
                $btn.prop('disabled', false);
            }
        });
    });

    /**
     * نمایش پیام
     */
    function showNotice(message, type) {
        // فوری پیام را نمایش دهید
        const noticeClass = type === 'error' ? 'notice-error' : 'notice-success';
        const notice = $(`
            <div class="notice ${noticeClass} is-dismissible" style="margin: 20px 0;">
                <p>${message}</p>
            </div>
        `);

        // اگر بخش کیف پول موجود باشد، بعد از آن اضافه کنید
        const $section = $('#carno-wallet-section');
        if ($section.length) {
            notice.insertBefore($section.closest('tr'));
        } else {
            // در غیر این صورت در بالای سبد خرید
            notice.insertBefore('.woocommerce-cart-form');
        }

        // خودکار حذف پیام
        setTimeout(function() {
            notice.fadeOut(function() {
                $(this).remove();
            });
        }, 3000);
    }

    // به‌روز‌رسانی خودکار سبد خرید موقع تغییر
    $(document.body).on('updated_wc_div', function() {
        // بعد از هر به‌روز‌رسانی، دکمه دوباره فعال شود
        if ($walletBtn.length) {
            $walletBtn.prop('disabled', false);
        }
    });
});
