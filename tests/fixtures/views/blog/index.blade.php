INDEX {{ $locale }} {{ $category?->name }} {{ $tag }}
@foreach ($posts as $post)
POST {{ $post->slug }} {{ $post->url() }}
@endforeach
@foreach ($alternates as $code => $url)
ALT {{ $code }} {{ $url }}
@endforeach
