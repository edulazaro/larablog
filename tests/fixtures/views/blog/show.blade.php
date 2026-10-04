SHOW {{ $post->title }}
{!! $post->html() !!}
@foreach ($alternates as $code => $url)
ALT {{ $code }} {{ $url }}
@endforeach
@foreach ($related as $other)
RELATED {{ $other->slug }}
@endforeach
