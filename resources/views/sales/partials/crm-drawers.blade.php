@if($lead->entity_type === 'organization')
@component('sales.partials.drawer', ['drawerId'=>'contact-create','drawerTitle'=>'Añadir contacto','drawerEyebrow'=>$lead->name])
<form method="post" action="{{ route('leads.contacts.store', $lead) }}">@csrf @include('sales.partials.contact-form', ['editingContact'=>null])<div class="form-footer"><button>Guardar contacto</button></div></form>
@endcomponent
@foreach($lead->contacts as $contact)
@component('sales.partials.drawer', ['drawerId'=>'contact-edit-'.$contact->id,'drawerTitle'=>'Editar contacto','drawerEyebrow'=>$lead->name])
<form method="post" action="{{ route('leads.contacts.update', [$lead, $contact]) }}">@csrf @method('PUT') @include('sales.partials.contact-form', ['editingContact'=>$contact])<div class="form-footer"><button>Guardar contacto</button></div></form>
@endcomponent
@endforeach
@endif
@if($lead->is_customer)
@component('sales.partials.drawer', ['drawerId'=>'service-create','drawerTitle'=>'Añadir servicio','drawerEyebrow'=>$lead->name])
<form method="post" action="{{ route('leads.services.store', $lead) }}">@csrf @include('sales.partials.service-form', ['editingService'=>null])<div class="form-footer"><button>Guardar servicio</button></div></form>
@endcomponent
@endif
@foreach($lead->services as $service)
@component('sales.partials.drawer', ['drawerId'=>'service-edit-'.$service->id,'drawerTitle'=>'Editar servicio','drawerEyebrow'=>$lead->name])
<form method="post" action="{{ route('leads.services.update', [$lead, $service]) }}">@csrf @method('PUT') @include('sales.partials.service-form', ['editingService'=>$service])<div class="form-footer"><button>Guardar servicio</button></div></form>
@endcomponent
@endforeach
