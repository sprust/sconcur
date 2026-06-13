<?php

namespace SConcur\Features;

enum MethodEnum: int
{
    case Unknown           = 0;
    case Sleep             = 1;
    case Mongodb = 2;
}
