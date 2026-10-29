{{-- Блок «Картинка»: варианты WebP размера card. --}}
<figure class="block-image">
    <x-fastleo::picture :src="$src ?? null" preset="card" :alt="$caption ?? ''" />
    @if (! empty($caption))<figcaption>{{ $caption }}</figcaption>@endif
</figure>
