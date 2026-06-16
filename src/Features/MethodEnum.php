<?php

namespace SConcur\Features;

enum MethodEnum: int
{
    case Unknown     = 0;
    case Sleep       = 1;
    case Mongodb     = 2;
    case HttpServe   = 3;
    case HttpRespond = 4;
    case HttpClient  = 5;
    case Mysql       = 6;
}
