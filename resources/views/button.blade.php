<div
    class="ssonbcom-auth-button {{ $buttonClass }}"
    data-url="{{ $url }}"
    @foreach ($attributes as $name => $value){{ $name }}="{{ $value }}" @endforeach
></div>
@if ($includeScript)
    <script src="{{ $jsUrl }}" defer></script>
@endif
