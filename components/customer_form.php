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

        <!--
            Profile photo. WhatsApp does not give out a contact's own
            picture, so this is one an agent sets; with none, the avatar
            falls back to initials exactly as it always has.
        -->
        <div class="details-photo">
            <span class="avatar avatar--xl" id="detailsAvatar">?</span>
            <div class="details-photo__actions">
                <button type="button" class="btn btn--ghost btn--sm" id="avatarPickBtn">Change photo</button>
                <button type="button" class="btn-link btn-link--danger" id="avatarRemoveBtn" hidden>Remove</button>
                <p class="details-photo__hint" id="avatarHint">JPEG, PNG, WebP or GIF, up to 5 MB.</p>
            </div>
            <input type="file" id="avatarInput" accept="image/*" hidden />
        </div>

        <!--
            The name the customer uses on WhatsApp. Read-only: it belongs
            to their account, not to us. Shown here because it is what the
            chat header displays when nobody has typed a name yet, which
            otherwise makes the empty fields below look like lost data.
        -->
        <div class="field" id="waNameField" hidden>
            <label>WhatsApp name</label>
            <div class="details-form__readonly details-form__readonly--name">
                <span id="field_wa_profile_name">—</span>
                <button type="button" class="btn-link" id="useWaNameBtn">Use as name</button>
            </div>
        </div>

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

        <!--
            A closed set, not free text: the point of the label is to be
            able to tell first contacts apart at a glance, and that stops
            working the moment "New" and "new customer" are two labels.
            A number the webhook has just met starts as New.
        -->
        <div class="field">
            <label for="field_label">Label</label>
            <select id="field_label" name="label">
                <option value="">No label</option>
                <option value="new">New customer</option>
                <option value="old">Old customer</option>
            </select>
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
            <!--
                The country is also worked out from the dialling prefix
                on every read (see config/countries.php) and shown beside
                the field. The field itself stays editable and always
                wins: a number can be a roaming SIM or a virtual line,
                and a prefix table must not overwrite what an agent knows.
            -->
            <div class="field">
                <label for="field_country">Country</label>
                <input type="text" id="field_country" name="country" autocomplete="off" />
                <p class="field__help field__help--inline" id="countryDetected" hidden></p>
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
