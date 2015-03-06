<h1>Register</h1>

@foreach ($errors->all() as $error)
    <p>{{ $error }}</p>
@endforeach

@if (Session::has('error'))
    <p>{{ Session::get('error') }}</p>
@endif

<form method="POST" action="/auth/register">
    <input type="hidden" name="_token" value="{{ csrf_token() }}">
    <input type="text" name="email" value="{{ old('email') }}">
    <input type="password" name="password">
    <input type="password" name="password_confirmation">
    <input type="submit" value="Register">
</form>
