<label>Nombre<input name="name" value="{{ old('name', $editingContact?->name) }}" required maxlength="255"></label>
<div class="grid"><label>Cargo o función<input name="role" value="{{ old('role', $editingContact?->role) }}"></label><label>Email<input type="email" name="email" value="{{ old('email', $editingContact?->email) }}"></label><label>Teléfono original<input name="phone_original" value="{{ old('phone_original', $editingContact?->phone_original) }}" maxlength="80"></label></div>
<p class="form-help">Para normalizar un teléfono incluye el código internacional, por ejemplo +54. Los valores incompletos se conservan para revisión.</p>
<input type="hidden" name="is_primary" value="0"><label class="check"><input type="checkbox" name="is_primary" value="1" @checked(old('is_primary', $editingContact?->is_primary))> Contacto principal</label>
<p class="form-help">Al elegir un contacto principal, sus datos se usan en las acciones de contacto existentes.</p>
