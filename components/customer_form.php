<?php declare(strict_types=1); ?>
<!-- components/customer_form.php -->
<div class="panel-overlay" id="panelOverlay" hidden></div>

<aside class="details-panel" id="detailsPanel" aria-hidden="true">
    <header class="details-panel__header">
        <h2>Customer Details</h2>
        <button class="btn btn--icon btn--ghost" id="closeDetailsBtn" aria-label="Close">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
        </button>
    </header>

    <form class="details-form" id="detailsForm">
        <input type="hidden" id="field_session_id" name="session_id" />

        <div class="details-form__row details-form__row--2">
            <div class="field">
                <label for="field_first_name">First name</label>
                <input type="text" id="field_first_name" name="first_name" autocomplete="off" />
            </div>
            <div class="field">
                <label for="field_last_name">Last name</label>
                <input type="text" id="field_last_name" name="last_name" autocomplete="off" />
            </div>
        </div>

        <div class="field">
            <label for="field_username">Username</label>
            <input type="text" id="field_username" name="username" autocomplete="off" />
        </div>

        <div class="details-form__row details-form__row--2">
            <div class="field">
                <label for="field_phone">Phone</label>
                <input type="tel" id="field_phone" name="phone" autocomplete="off" />
            </div>
            <div class="field">
                <label for="field_email">Email</label>
                <input type="email" id="field_email" name="email" autocomplete="off" />
            </div>
        </div>

        <div class="details-form__row details-form__row--2">
            <div class="field">
                <label for="field_wa_id">WhatsApp ID</label>
                <input type="tel" id="field_wa_id" name="wa_id" inputmode="numeric" autocomplete="off" />
            </div>
            <div class="field">
                <label for="field_wa_profile_name">WhatsApp profile</label>
                <input type="text" id="field_wa_profile_name" name="wa_profile_name" autocomplete="off" />
            </div>
        </div>

        <div class="details-form__row details-form__row--2">
            <div class="field">
                <label for="field_country">Country</label>
                <input type="text" id="field_country" name="country" autocomplete="off" />
            </div>
            <div class="field">
                <label for="field_city">City</label>
                <input type="text" id="field_city" name="city" autocomplete="off" />
            </div>
        </div>

        <div class="field">
            <label for="field_address">Address</label>
            <input type="text" id="field_address" name="address" autocomplete="off" />
        </div>

        <div class="field">
            <label for="field_tax_id">Tax ID</label>
            <input type="text" id="field_tax_id" name="tax_id" autocomplete="off" />
        </div>

        <div class="field">
            <label for="field_details">Notes</label>
            <textarea id="field_details" name="details" rows="4" placeholder="Internal notes about this customer…"></textarea>
        </div>

        <div class="field">
            <label>Session ID</label>
            <div class="details-form__readonly" id="field_session_id_display">—</div>
        </div>

        <div class="details-form__actions">
            <button type="submit" class="btn btn--primary btn--block" id="saveDetailsBtn">
                Save changes
            </button>
        </div>
    </form>
</aside>
