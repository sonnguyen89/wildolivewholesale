<ul class="woocommerce-error" style="display:none">
</ul>
<div>
	<p class="form-row form-row-wide">
		<label for="EWAY_TEMPCARDNUMBER"><?php esc_html_e( 'Card Number', 'wc-eway' ); ?><span class="required">*</span></label>
		<input id="EWAY_TEMPCARDNUMBER" class="input-text wc-credit-card-form-card-number" type="text" maxlength="20" autocomplete="on" autocompletetype="cc-number" placeholder="•••• •••• •••• ••••" name="EWAY_TEMPCARDNUMBER" />
	</p>
	<p class="form-row form-row-first">
		<label for="EWAY_EXPIRY"><?php esc_html_e( 'Expiry (MM/YY)', 'wc-eway' ); ?><span class="required">*</span></label>
		<input id="EWAY_EXPIRY" class="input-text wc-credit-card-form-card-expiry" type="text" autocomplete="on" autocompletetype="cc-exp" placeholder="MM / YY" name="EWAY_EXPIRY" />
	</p>
	<p class="form-row form-row-last">
		<label for="EWAY_CARDCVN"><?php esc_html_e( 'Card CVN Code', 'wc-eway' ); ?><span class="required">*</span></label>
		<input id="EWAY_CARDCVN" class="input-text wc-credit-card-form-card-cvc" type="text" autocomplete="off" placeholder="CVN" autocompletetype="cc-csc" name="EWAY_CARDCVN" />
	</p>
</div>
<input type="submit" value="<?php esc_attr_e( 'Confirm and Pay', 'wc-eway' ); ?>" class="submit buy button">
