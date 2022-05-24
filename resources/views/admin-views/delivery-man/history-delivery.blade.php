@extends('layouts.admin.app')

@section('title',__('delivery men History'))
@section('content')
    <div class="content container-fluid">
        <!-- Page Header -->
        <div class="page-header">
            <div class="row align-items-center">
                <div class="col-sm mb-2 mb-sm-0">
                    <h1 class="page-header-title"><i class="tio-filter-list"></i> {{__('messages.deliverymen')}}</h1>
                </div>
                {{--<a href="{{route('admin.delivery-man.add')}}" class="btn btn-primary pull-right"><i
                                class="tio-add-circle"></i> {{__('messages.add')}} {{__('messages.deliveryman')}}</a>--}}
            </div>
        </div>
        <!-- End Page Header -->
        <div class="row gx-2 gx-lg-3">
            <div class="col-sm-12 col-lg-12 mb-3 mb-lg-2">
                <!-- Card -->
                <div class="card">
                    <!-- Header -->
                    <div class="card-header p-1">
                        {{-- <h5>{{__('messages.deliveryman')}} {{__('messages.list')}}<span class="badge badge-soft-dark ml-2" id="itemCount">{{$delivery_man->total()}}</span></h5>
                        <form action="javascript:" id="search-form" >
                                        <!-- Search -->
                            @csrf
                            <div class="input-group input-group-merge input-group-flush">
                                <div class="input-group-prepend">
                                    <div class="input-group-text">
                                        <i class="tio-search"></i>
                                    </div>
                                </div>
                                <input id="datatableSearch_" type="search" name="search" class="form-control"
                                        placeholder="{{__('messages.search')}}" aria-label="Search" required>
                                <button type="submit" class="btn btn-light">{{__('messages.search')}}</button>

                            </div>
                            <!-- End Search -->
                        </form> --}}
                    </div>
                    <!-- End Header -->

                    <!-- Table -->
                    <div class="table-responsive datatable-custom">
                        <table id="columnSearchDatatable"
                               class="table table-borderless table-thead-bordered table-nowrap table-align-middle card-table"
                               data-hs-datatables-options='{
                                 "order": [],
                                 "orderCellsTop": true,
                                 "paging":false
                               }'>
                            <thead class="thead-light">
                            <tr>
                                <th class="text-capitalize">delivery_man_id</th>
                                <th class="text-capitalize">order_id</th>
                                <th class="text-capitalize">time</th>
                                <th class="text-capitalize">longitude</th>
                                <th class="text-capitalize">latitude</th>
                                <th class="text-capitalize">location</th>
                            </tr>
                            </thead>

                            <tbody id="set-rows">
                            @foreach($delivery_man->delivery_history as $dm)
                                <tr>
                                    <td>{{$dm->delivery_man_id}}</td>
                                    <td> {{$dm->order_id}}</td>
                                    <td>{{$dm->time}}</td>
                                     <td>{{$dm->longitude}}</td>
                                    <td> {{$dm->latitude}}</td>
                                    <td>{{$dm->location}}</td>
                                    {{-- <td>
                                         <a class="btn btn-sm btn-white" href="{{route('admin.delivery-man.history',[$dm['id']])}}" title="">Shifts
                                        </a>
                                        <a class="btn btn-sm btn-white" href="{{route('admin.delivery-man.edit',[$dm['id']])}}" title="{{__('messages.edit')}}"><i class="tio-edit"></i>
                                        </a>
                                        <a class="btn btn-sm btn-white text-danger" href="javascript:" onclick="form_alert('delivery-man-{{$dm['id']}}','Want to remove this deliveryman ?')" title="{{__('messages.delete')}}"><i class="tio-delete-outlined"></i>
                                        </a>
                                        <form action="{{route('admin.delivery-man.delete',[$dm['id']])}}" method="post" id="delivery-man-{{$dm['id']}}">
                                            @csrf @method('delete')
                                        </form>
                                    </td> --}}
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                        <hr>

                        <div class="page-area">
                            <table>
                                <tfoot>
                                {{-- {!! $delivery_man->links() !!} --}}
                                </tfoot>
                            </table>
                        </div>

                    </div>
                    <!-- End Table -->
                </div>
                <!-- End Card -->
            </div>
        </div>
    </div>

@endsection

@push('script_2')
    <script>
        $(document).on('ready', function () {
            // INITIALIZATION OF DATATABLES
            // =======================================================
            var datatable = $.HSCore.components.HSDatatables.init($('#columnSearchDatatable'));

            $('#column1_search').on('keyup', function () {
                datatable
                    .columns(1)
                    .search(this.value)
                    .draw();
            });

            $('#column2_search').on('keyup', function () {
                datatable
                    .columns(2)
                    .search(this.value)
                    .draw();
            });

            $('#column3_search').on('keyup', function () {
                datatable
                    .columns(3)
                    .search(this.value)
                    .draw();
            });

            $('#column4_search').on('keyup', function () {
                datatable
                    .columns(4)
                    .search(this.value)
                    .draw();
            });


            // INITIALIZATION OF SELECT2
            // =======================================================
            $('.js-select2-custom').each(function () {
                var select2 = $.HSCore.components.HSSelect2.init($(this));
            });
        });
    </script>

    <script>
        $('#search-form').on('submit', function (e) {
            e.preventDefault();
            var formData = new FormData(this);
            $.ajaxSetup({
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                }
            });
            $.post({
                url: '{{route('admin.delivery-man.search')}}',
                data: formData,
                cache: false,
                contentType: false,
                processData: false,
                beforeSend: function () {
                    $('#loading').show();
                },
                success: function (data) {
                    $('#set-rows').html(data.view);
                    $('#itemCount').html(data.count);
                    $('.page-area').hide();
                },
                complete: function () {
                    $('#loading').hide();
                },
            });
        });
    </script>
@endpush
