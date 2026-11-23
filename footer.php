<footer id="pk-footer">
<div class="pk-footer-inner">
<p><?php echo esc_html(get_bloginfo('name'));?> &copy; <?php echo date('Y');?></p>
</div>
</footer>
<?php if (\\PkStore\\Core\\Feature\\FeatureManager::getInstance()->isEnabled('ai_chat')): ?>
<div data-chat-widget aria-hidden="true" id="pk-chat-widget">
<div data-chat-messages class="pk-chat__messages" role="log" aria-live="polite"></div>
<div class="pk-chat__input-area">
<textarea data-chat-input class="pk-chat__input" placeholder="Ask about products... / پروڈکٹ کے بارے میں پوچھیں" rows="2" maxlength="500"></textarea>
<button data-chat-submit class="pk-chat__send" aria-label="Send">Send ↑</button>
</div>
<button data-chat-close class="pk-chat__close" aria-label="Close chat">✕</button>
</div>
<button data-chat-toggle class="pk-chat__toggle" aria-label="Open chat" aria-expanded="false">🤖 <span>Dukaan AI</span></button>
<?php endif; ?>
<?php wp_footer();?>
</body></html>
