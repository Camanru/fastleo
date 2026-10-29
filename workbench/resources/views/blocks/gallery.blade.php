{{-- Блок «Галерея». --}}
<div class="block-gallery">
    @foreach ($photos ?? [] as $photo)
        <x-fastleo::picture :src="$photo" preset="card" alt="" />
    @endforeach
</div>
