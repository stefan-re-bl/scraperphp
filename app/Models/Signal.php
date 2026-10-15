<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Signal extends Model
{
    protected $guarded = [];

    public static function label(string $type): string
    {
        return ['no_website' => 'Sin sitio registrado', 'http_page_observed' => 'Página consultada por HTTP', 'no_https' => 'Ausencia de HTTPS confirmada', 'phone_format_valid' => 'Teléfono utilizable', 'phone_format_invalid' => 'Teléfono a revisar', 'phone_likely_mobile' => 'Posible móvil', 'channel_whatsapp' => 'WhatsApp visible', 'channel_email_visible' => 'Email visible', 'channel_form' => 'Formulario visible', 'booking_business' => 'Actividad con reservas', 'booking_system_visible' => 'Sistema de reserva visible', 'manual_booking_dependency' => 'Reservas manuales', 'manual_contact_dependency' => 'Gestión manual a revisar', 'operational_complexity' => 'Equipo o sedes', 'no_visible_booking' => 'Reserva online a revisar', 'weak_cta' => 'Contacto poco claro', 'weak_website_content' => 'Contenido a revisar', 'website_unreachable' => 'Sitio no verificado', 'hard_to_find_contact' => 'Contacto a revisar', 'ads_active' => 'Invierte en captación', 'clear_offer' => 'Oferta clara', 'observed_problem' => 'Problema confirmado', 'website_verified' => 'Sitio público verificado', 'website_functional' => 'Página funcional observada', 'website_unknown' => 'Sitio no verificable', 'website_access_restricted' => 'Acceso de extracción restringido', 'products_visible' => 'Productos publicados', 'catalog_visible' => 'Catálogo público', 'ecommerce_visible' => 'Compra online visible', 'online_purchase_visible' => 'Acción de compra publicada', 'product_sales' => 'Venta de productos', 'service_offered' => 'Servicio publicado', 'technology_detected' => 'Tecnología detectada', 'channel_phone_visible' => 'Teléfono público', 'identity_conflict' => 'Identidad por revisar', 'dns_public' => 'DNS público comprobado', 'osm_identity_verified' => 'Identidad corroborada en OpenStreetMap', 'osm_unknown' => 'Fuente complementaria no verificable', 'crm_history_available' => 'Historial comercial disponible', 'structured_data_unknown' => 'Datos estructurados no interpretables', 'contact_data_conflict' => 'Contactos por conciliar', 'maps_source_record' => 'Procedencia de Google Maps'][$type] ?? str_replace('_', ' ', $type);
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('is_current', true)->where(fn (Builder $dated): Builder => $dated->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    protected function casts(): array
    {
        return ['detected_at' => 'datetime', 'expires_at' => 'datetime', 'confidence' => 'float', 'is_current' => 'boolean', 'metadata' => 'array'];
    }
}
